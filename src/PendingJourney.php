<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Generator;
use Illuminate\Support\Facades\DB;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\OrderNotViableException;

/**
 * Fluent executor returned by RunsJourneys::journey(). Runs the canonical
 * order plus N seeded shuffles, each wrapped so state resets between trails.
 */
final class PendingJourney
{
    private int $shuffles = 10;

    private ?int $seed = null;

    private int $repeatBias = 1;

    private ?int $exhaustiveLimit = null;

    /** @var list<Closure(Trail): void> */
    private array $onTrail = [];

    /** @var non-empty-list<Journey> */
    private readonly array $journeys;

    /**
     * @param  Journey|list<Journey>  $journey  One journey, or several to interleave into each trail.
     * @param  Closure(Closure): void  $wrapper  Wraps each trail; the default (from RunsJourneys) rolls back a database transaction.
     */
    public function __construct(
        Journey|array $journey,
        private Closure $wrapper,
        private readonly ?HttpDriver $http = null,
    ) {
        $journeys = is_array($journey) ? $journey : [$journey];

        if ($journeys === []) {
            throw new InvalidJourneyException('At least one journey is required.');
        }

        $this->journeys = $journeys;
    }

    /** How many seeded shuffled trails to run after the canonical one. */
    public function shuffles(int $count): self
    {
        $this->shuffles = $count;

        return $this;
    }

    /** Replay a single shuffled trail with this exact seed (also settable via RUNABOUT_SEED). */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Bias the shuffled picker toward repeatable steps: each repeatable
     * step's weight is multiplied by $bias. Re-running steps is how
     * idempotency, replace-logic, and counter bugs surface, so this mode
     * finds them in far fewer trails than uniform shuffling.
     */
    public function repeatHeavy(int $bias = 5): self
    {
        $this->repeatBias = max(1, $bias);

        return $this;
    }

    /**
     * Run every valid ordering of the journey's steps (each step once)
     * instead of sampling shuffles. Only sensible for small journeys: the
     * orderings grow factorially, so anything beyond $limit is refused.
     */
    public function exhaustive(int $limit = 720): self
    {
        $this->exhaustiveLimit = $limit;

        return $this;
    }

    /**
     * Observe every completed trail: the callback receives each Trail right
     * after its state has been reset, in every mode. Failed trails are not
     * observed — they already describe themselves in the failure output.
     *
     * @param  Closure(Trail): void  $callback
     */
    public function onTrail(Closure $callback): self
    {
        $this->onTrail[] = $callback;

        return $this;
    }

    /**
     * Replace the trail wrapper entirely — for apps that need a bespoke reset
     * (multiple connections, external stores) instead of the transaction
     * default or table truncation.
     *
     * @param  Closure(Closure): void  $wrapper
     */
    public function resetWith(Closure $wrapper): self
    {
        $this->wrapper = $wrapper;

        return $this;
    }

    /**
     * Reset by truncating the given tables after each trail instead of
     * rolling back a transaction. Use when the code under test commits or
     * manages transactions itself.
     */
    public function resetByTruncating(string ...$tables): self
    {
        return $this->resetWith(function (Closure $trail) use ($tables): void {
            try {
                $trail();
            } finally {
                $connection = DB::connection();
                $connection->getSchemaBuilder()->disableForeignKeyConstraints();

                try {
                    foreach ($tables as $table) {
                        $connection->table($table)->truncate();
                    }
                } finally {
                    $connection->getSchemaBuilder()->enableForeignKeyConstraints();
                }
            }
        });
    }

    public function run(): void
    {
        $runner = new JourneyRunner;

        $exhaustiveLimit = $this->exhaustiveLimit;

        if ($exhaustiveLimit !== null) {
            $this->registerVerbosePrinter(total: null);
            $this->runExhaustive($runner, $exhaustiveLimit);

            return;
        }

        $replaySeed = $this->seed ?? $this->seedFromEnvironment();

        if ($replaySeed !== null) {
            $this->registerVerbosePrinter(total: 1);
            $this->trail($runner, $replaySeed, shuffle: true);

            return;
        }

        $this->registerVerbosePrinter(total: 1 + $this->shuffles);
        $this->trail($runner, $this->deriveSeed(0), shuffle: false);

        for ($i = 1; $i <= $this->shuffles; $i++) {
            $this->trail($runner, $this->deriveSeed($i), shuffle: true);
        }
    }

    private function trail(JourneyRunner $runner, int $seed, bool $shuffle): void
    {
        $trail = null;

        ($this->wrapper)(function () use ($runner, $seed, $shuffle, &$trail): void {
            $trail = $runner->runInterleaved($this->journeys, $seed, $shuffle, $this->http, $this->repeatBias);
        });

        if ($trail instanceof Trail) {
            $this->notify($trail);
        }
    }

    private function notify(Trail $trail): void
    {
        foreach ($this->onTrail as $callback) {
            $callback($trail);
        }
    }

    /** When RUNABOUT_VERBOSE is set, print every completed trail to STDERR (stdout is swallowed by the test runner). */
    private function registerVerbosePrinter(?int $total): void
    {
        if (in_array(getenv('RUNABOUT_VERBOSE'), [false, '', '0'], true)) {
            return;
        }

        $names = implode(' + ', array_map(class_basename(...), $this->journeys));
        $count = 0;

        $this->onTrail[] = function (Trail $trail) use ($names, $total, &$count): void {
            $count++;

            fwrite(STDERR, sprintf(
                "\n[%s] trail %s (%s, seed %d)\n%s\n",
                $names,
                $total === null ? (string) $count : sprintf('%d/%d', $count, $total),
                $trail->mode(),
                $trail->seed(),
                $trail->describe(markLast: false),
            ));
        };
    }

    private function runExhaustive(JourneyRunner $runner, int $limit): void
    {
        if (count($this->journeys) > 1) {
            throw new InvalidJourneyException('Exhaustive mode is not available for interleaved journeys: the ordering space is the product of the instances\' orderings.');
        }

        $journey = $this->journeys[0];
        $count = count($journey->steps());

        $orderings = 1;
        for ($i = 2; $i <= $count; $i++) {
            $orderings *= $i;

            if ($orderings > $limit) {
                throw new InvalidJourneyException(sprintf(
                    'Exhaustive mode would run more than %d orderings for the %d steps of %s. Shrink the journey or raise the limit: exhaustive(limit: ...).',
                    $limit,
                    $count,
                    $journey::class,
                ));
            }
        }

        $index = 0;

        foreach ($this->permutations(range(0, $count - 1)) as $order) {
            $seed = $this->deriveSeed($index++);

            $trail = null;

            try {
                ($this->wrapper)(function () use ($runner, $journey, $order, $seed, &$trail): void {
                    $trail = $runner->runOrder($journey, $order, $seed, $this->http);
                });
            } catch (OrderNotViableException) {
                // Not every permutation satisfies the constraints; skip it.
            }

            if ($trail instanceof Trail) {
                $this->notify($trail);
            }
        }
    }

    /**
     * @param  list<int>  $items
     * @return Generator<int, list<int>>
     */
    private function permutations(array $items): Generator
    {
        if (count($items) <= 1) {
            yield $items;

            return;
        }

        foreach ($items as $i => $first) {
            $rest = $items;
            unset($rest[$i]);

            foreach ($this->permutations(array_values($rest)) as $permutation) {
                yield [$first, ...$permutation];
            }
        }
    }

    /**
     * Deterministic by default: derived from the journey class and the trail
     * index, so CI is stable run to run. RUNABOUT_SEED overrides for replay,
     * and RUNABOUT_RANDOMIZE=1 explores fresh seeds on every run — meant for
     * a nightly job that hunts orderings the fixed seeds never visit. Every
     * failure still prints its seed, so a nightly find replays exactly.
     */
    private function deriveSeed(int $index): int
    {
        if ($this->explore()) {
            return random_int(0, 2147483647);
        }

        return crc32(implode('+', array_map(fn (Journey $journey): string => $journey::class, $this->journeys)).'#'.$index);
    }

    private function explore(): bool
    {
        $flag = getenv('RUNABOUT_RANDOMIZE');

        return ! in_array($flag, [false, '', '0'], true);
    }

    private function seedFromEnvironment(): ?int
    {
        $seed = getenv('RUNABOUT_SEED');

        return is_string($seed) && is_numeric($seed) ? (int) $seed : null;
    }
}
