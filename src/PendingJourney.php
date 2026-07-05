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

    /** @param Closure(Closure): void $wrapper Wraps each trail; the default (from RunsJourneys) rolls back a database transaction. */
    public function __construct(
        private readonly Journey $journey,
        private Closure $wrapper,
        private readonly ?HttpDriver $http = null,
    ) {}

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

        if ($this->exhaustiveLimit !== null) {
            $this->runExhaustive($runner, $this->exhaustiveLimit);

            return;
        }

        $replaySeed = $this->seed ?? $this->seedFromEnvironment();

        if ($replaySeed !== null) {
            $this->trail($runner, $replaySeed, shuffle: true);

            return;
        }

        $this->trail($runner, $this->deriveSeed(0), shuffle: false);

        for ($i = 1; $i <= $this->shuffles; $i++) {
            $this->trail($runner, $this->deriveSeed($i), shuffle: true);
        }
    }

    private function trail(JourneyRunner $runner, int $seed, bool $shuffle): void
    {
        ($this->wrapper)(function () use ($runner, $seed, $shuffle): void {
            $runner->run($this->journey, $seed, $shuffle, $this->http, $this->repeatBias);
        });
    }

    private function runExhaustive(JourneyRunner $runner, int $limit): void
    {
        $count = count($this->journey->steps());

        $orderings = 1;
        for ($i = 2; $i <= $count; $i++) {
            $orderings *= $i;

            if ($orderings > $limit) {
                throw new InvalidJourneyException(sprintf(
                    'Exhaustive mode would run more than %d orderings for the %d steps of %s. Shrink the journey or raise the limit: exhaustive(limit: ...).',
                    $limit,
                    $count,
                    $this->journey::class,
                ));
            }
        }

        $index = 0;

        foreach ($this->permutations(range(0, $count - 1)) as $order) {
            $seed = $this->deriveSeed($index++);

            try {
                ($this->wrapper)(function () use ($runner, $order, $seed): void {
                    $runner->runOrder($this->journey, $order, $seed, $this->http);
                });
            } catch (OrderNotViableException) {
                // Not every permutation satisfies the constraints; skip it.
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
     * index, so CI is stable run to run. RUNABOUT_SEED overrides for replay.
     */
    private function deriveSeed(int $index): int
    {
        return crc32($this->journey::class.'#'.$index);
    }

    private function seedFromEnvironment(): ?int
    {
        $seed = getenv('RUNABOUT_SEED');

        return is_string($seed) && is_numeric($seed) ? (int) $seed : null;
    }
}
