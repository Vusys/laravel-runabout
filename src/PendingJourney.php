<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Generator;
use Illuminate\Support\Facades\DB;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
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

    /** @var array<array-key, mixed>|null A seed + token list to replay verbatim instead of shuffling. */
    private ?array $trailArtifact = null;

    /** @var list<Closure(Trail): void> */
    private array $onTrail = [];

    /** @var list<string|null>|null Connections to wrap in a rolled-back transaction; null until resetConnections() is called. */
    private ?array $transactedConnections = null;

    /** @var list<Closure(): void> Cleanups run after each trail, for non-transactional external stores. */
    private array $externalResets = [];

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
     * Replay one explicit trail — a seed plus an ordered token list — instead
     * of shuffling. This is the mode a shrunk trail (or any RUNABOUT_TRAIL
     * value) reproduces under: the order travels with the artifact, so unlike a
     * bare seed it reproduces repeat-heavy and partial trails too. Pass the
     * artifact array ({seed, steps}) or its JSON string; a leading "@" on the
     * string reads it from that file path.
     *
     * @param  array<array-key, mixed>|string  $artifact
     */
    public function trail(array|string $artifact): self
    {
        $this->trailArtifact = is_string($artifact) ? $this->decodeArtifact($artifact) : $artifact;

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

    /**
     * Reset by rolling back a transaction on each of several connections,
     * instead of the single default connection — the multi-connection
     * equivalent of the default reset, for journeys that write across more than
     * one database. Composes with resetExternal(). Pass no arguments to transact
     * just the default connection.
     */
    public function resetConnections(string ...$connections): self
    {
        $this->transactedConnections = $connections === [] ? [null] : array_values($connections);

        return $this->rebuildTrailReset();
    }

    /**
     * Register a cleanup to run after each trail, once the transacted
     * connections have rolled back — for non-transactional stores (a Mongo or
     * Elasticsearch wipe, a cache flush) that a transaction rollback cannot
     * undo. May be called more than once; cleanups run in the order registered.
     * Composes with resetConnections() (which defaults to the single default
     * connection when resetExternal() is used alone).
     *
     * @param  Closure(): void  $cleanup
     */
    public function resetExternal(Closure $cleanup): self
    {
        $this->externalResets[] = $cleanup;

        return $this->rebuildTrailReset();
    }

    /**
     * Build the trail wrapper for resetConnections()/resetExternal(): begin a
     * transaction on each connection, run the trail, then in a finally roll
     * each back (in reverse) and run every external cleanup. Rebuilt whenever
     * either method is called so the two compose regardless of call order.
     */
    private function rebuildTrailReset(): self
    {
        $connections = $this->transactedConnections ?? [null];
        $externalResets = $this->externalResets;

        $this->wrapper = function (Closure $trail) use ($connections, $externalResets): void {
            foreach ($connections as $name) {
                DB::connection($name)->beginTransaction();
            }

            try {
                $trail();
            } finally {
                foreach (array_reverse($connections) as $name) {
                    DB::connection($name)->rollBack();
                }

                foreach ($externalResets as $cleanup) {
                    $cleanup();
                }
            }
        };

        return $this;
    }

    public function run(): void
    {
        $coverage = $this->coverageCollector();

        $this->execute();

        if ($coverage instanceof TrailCoverage) {
            fwrite(STDERR, sprintf("\n[%s] trail coverage\n%s\n", $this->names(), $coverage->describe()));
        }
    }

    private function execute(): void
    {
        $runner = new JourneyRunner;

        $exhaustiveLimit = $this->exhaustiveLimit;

        if ($exhaustiveLimit !== null) {
            $this->registerVerbosePrinter(total: null);
            $this->runExhaustive($runner, $exhaustiveLimit);

            return;
        }

        $artifact = $this->trailArtifact ?? $this->artifactFromEnvironment();

        if ($artifact !== null) {
            $this->registerVerbosePrinter(total: 1);
            $this->replay($runner, $artifact);

            return;
        }

        $replaySeed = $this->seed ?? $this->seedFromEnvironment();

        if ($replaySeed !== null) {
            $this->registerVerbosePrinter(total: 1);
            $this->runTrail($runner, $replaySeed, shuffle: true);

            return;
        }

        $this->registerVerbosePrinter(total: 1 + $this->shuffles);
        $this->runTrail($runner, $this->deriveSeed(0), shuffle: false);

        for ($i = 1; $i <= $this->shuffles; $i++) {
            $this->runTrail($runner, $this->deriveSeed($i), shuffle: true);
        }
    }

    private function runTrail(JourneyRunner $runner, int $seed, bool $shuffle): void
    {
        $trail = null;

        try {
            ($this->wrapper)(function () use ($runner, $seed, $shuffle, &$trail): void {
                $trail = $runner->runInterleaved($this->journeys, $seed, $shuffle, $this->http, $this->repeatBias);
            });
        } catch (JourneyFailedException $failure) {
            // A failure has already cost a red build; spending a few seconds to
            // minimise it to the executions that matter is the right default.
            throw $this->shrink($runner, $failure);
        }

        if ($trail instanceof Trail) {
            $this->notify($trail);
        }
    }

    /**
     * Minimise a failing trail to the shortest token list that reproduces the
     * same failure, and re-frame the exception around it. Off for canonical
     * (reproduces by re-running) and structural failures (deadlocks, runaways,
     * broken journeys — no meaningful subsequence), and skippable with
     * RUNABOUT_SHRINK=0.
     */
    private function shrink(JourneyRunner $runner, JourneyFailedException $failure): JourneyFailedException
    {
        if (! $this->shrinkingEnabled() || ! in_array($failure->trail()->mode(), ['shuffled', 'repeat-heavy'], true)) {
            return $failure;
        }

        if ($failure->getPrevious() instanceof InvalidJourneyException) {
            return $failure;
        }

        $original = $failure->trail();
        $tokens = $original->tokens();
        $count = count($tokens);

        if ($count < 2) {
            return $failure;
        }

        $signature = FailureSignature::from($failure);
        $seed = $original->seed();

        // Length first: reduce to the shortest reproducing subsequence.
        $result = (new TrailShrinker(
            fn (array $positions): bool => $this->candidateReproduces($runner, $seed, $this->tokensAt($tokens, $positions), $signature),
            $this->shrinkBudget(),
        ))->shrink(range(0, $count - 1));

        $shrunkTokens = $this->tokensAt($tokens, $result['positions']);
        $sequenceReduced = count($shrunkTokens) < $count;

        // Then values: minimise the draws inside the now-minimal trail.
        $value = $this->valueShrink($runner, $seed, $shrunkTokens, $signature);
        $valueReduced = $value['forced'] !== [];

        if (! $sequenceReduced && ! $valueReduced) {
            return $failure;
        }

        $replay = $this->replayFailure($runner, $seed, $shrunkTokens, $value['forced']);

        return $replay instanceof JourneyFailedException
            ? JourneyFailedException::shrunk($replay, $count, $seed, $result['replays'] + $value['replays'])
            : $failure;
    }

    /**
     * Minimise the drawn values inside the already-length-minimal trail. Replays
     * it once with recording to capture each surviving execution's draws, then
     * pushes each toward the low end of its domain (keeping the same failure).
     * Returns only the tokens whose values actually reduced — the rest reproduce
     * from their stream and need no pinning.
     *
     * @param  list<TrailToken>  $shrunkTokens
     * @return array{forced: array<int, array<int, int>>, replays: int}
     */
    private function valueShrink(JourneyRunner $runner, int $seed, array $shrunkTokens, FailureSignature $signature): array
    {
        $baselineTrail = $this->recordBaseline($runner, $seed, $shrunkTokens);

        if (! $baselineTrail instanceof Trail) {
            return ['forced' => [], 'replays' => 0];
        }

        /** @var array<int, list<Draw>> $baseline */
        $baseline = [];
        foreach ($shrunkTokens as $index => $token) {
            if ($baselineTrail->isOpaqueAt($index)) {
                continue; // touched the raw randomizer — not value-shrinkable
            }

            $draws = $baselineTrail->drawsAt($index);
            if ($draws !== []) {
                $baseline[$index] = $draws;
            }
        }

        if ($baseline === []) {
            return ['forced' => [], 'replays' => 1];
        }

        $result = (new ValueShrinker(
            fn (array $forced): bool => $this->candidateReproduces($runner, $seed, $shrunkTokens, $signature, $forced),
            $this->shrinkBudget(),
        ))->shrink($baseline);

        // Pin only the tokens whose values changed; unchanged draws reproduce
        // from the stream, so leaving them unpinned keeps the artifact clean.
        $reduced = [];
        foreach ($result['forced'] as $token => $values) {
            $baselineValues = array_map(fn (Draw $draw): int => $draw->value, $baseline[$token]);

            if ($values !== $baselineValues) {
                $reduced[$token] = $values;
            }
        }

        return ['forced' => $reduced, 'replays' => $result['replays'] + 1];
    }

    /**
     * Replay the minimal token list once to capture its recorded draws — the
     * baseline for value shrinking. It reproduces (so it throws); the thrown
     * failure carries the trail with the draws attached.
     *
     * @param  list<TrailToken>  $tokens
     */
    private function recordBaseline(JourneyRunner $runner, int $seed, array $tokens): ?Trail
    {
        try {
            ($this->wrapper)(function () use ($runner, $seed, $tokens): void {
                $runner->runTokens($this->journeys, $seed, $tokens, $this->http);
            });
        } catch (JourneyFailedException $failure) {
            return $failure->trail();
        } catch (OrderNotViableException|InvalidJourneyException) {
            return null;
        }

        return null;
    }

    /**
     * The tokens at the given positions, in order — the bridge between the
     * shrinker's position list and a replayable token list.
     *
     * @param  list<TrailToken>  $tokens
     * @param  array<array-key, mixed>  $positions
     * @return list<TrailToken>
     */
    private function tokensAt(array $tokens, array $positions): array
    {
        $selected = [];

        foreach ($positions as $position) {
            if (is_int($position) && array_key_exists($position, $tokens)) {
                $selected[] = $tokens[$position];
            }
        }

        return $selected;
    }

    /**
     * Replay a candidate token order under the reset wrapper and report whether
     * it fails the same way. A non-viable order (a removed dependency) or a
     * structural error counts as "does not reproduce"; probe replays never
     * notify onTrail — they are internal to the search.
     *
     * @param  list<TrailToken>  $candidate
     * @param  array<int, array<int, int>>  $forcedDraws
     */
    private function candidateReproduces(JourneyRunner $runner, int $seed, array $candidate, FailureSignature $signature, array $forcedDraws = []): bool
    {
        try {
            ($this->wrapper)(function () use ($runner, $seed, $candidate, $forcedDraws): void {
                $runner->runTokens($this->journeys, $seed, $candidate, $this->http, $forcedDraws);
            });
        } catch (JourneyFailedException $failure) {
            return FailureSignature::from($failure)->matches($signature);
        } catch (OrderNotViableException|InvalidJourneyException) {
            return false;
        }

        return false;
    }

    /**
     * Replay the shrunk token list one final time to capture its failure in
     * replayed mode (whose message carries the RUNABOUT_TRAIL replay line, with
     * any value-shrunk draws pinned).
     *
     * @param  list<TrailToken>  $tokens
     * @param  array<int, array<int, int>>  $forcedDraws
     */
    private function replayFailure(JourneyRunner $runner, int $seed, array $tokens, array $forcedDraws = []): ?JourneyFailedException
    {
        try {
            ($this->wrapper)(function () use ($runner, $seed, $tokens, $forcedDraws): void {
                $runner->runTokens($this->journeys, $seed, $tokens, $this->http, $forcedDraws);
            });
        } catch (JourneyFailedException $failure) {
            return $failure;
        } catch (OrderNotViableException|InvalidJourneyException) {
            return null;
        }

        return null;
    }

    private function shrinkingEnabled(): bool
    {
        return getenv('RUNABOUT_SHRINK') !== '0';
    }

    private function shrinkBudget(): int
    {
        $budget = getenv('RUNABOUT_SHRINK_BUDGET');

        return is_string($budget) && ctype_digit($budget) && (int) $budget > 0 ? (int) $budget : 200;
    }

    private function notify(Trail $trail): void
    {
        foreach ($this->onTrail as $callback) {
            $callback($trail);
        }
    }

    /**
     * When RUNABOUT_COVERAGE is set, collect every completed trail into an
     * aggregate coverage summary, printed to STDERR once the run finishes.
     * A failing run reports its failure instead — nothing is printed.
     */
    private function coverageCollector(): ?TrailCoverage
    {
        if (in_array(getenv('RUNABOUT_COVERAGE'), [false, '', '0'], true)) {
            return null;
        }

        $coverage = new TrailCoverage;

        $this->onTrail($coverage->record(...));

        return $coverage;
    }

    /** When RUNABOUT_VERBOSE is set, print every completed trail to STDERR (stdout is swallowed by the test runner). */
    private function registerVerbosePrinter(?int $total): void
    {
        if (in_array(getenv('RUNABOUT_VERBOSE'), [false, '', '0'], true)) {
            return;
        }

        $names = $this->names();
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

    private function names(): string
    {
        return implode(' + ', array_map(class_basename(...), $this->journeys));
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

    /** @return array<array-key, mixed>|null */
    private function artifactFromEnvironment(): ?array
    {
        $raw = getenv('RUNABOUT_TRAIL');

        return is_string($raw) && $raw !== '' ? $this->decodeArtifact($raw) : null;
    }

    /**
     * Replay one explicit token order. A non-viable order (a token whose step
     * is no longer reachable) is a broken artifact, not a test failure, so it
     * surfaces as an InvalidJourneyException; a genuine assertion or invariant
     * failure propagates untouched, which is how a replay reproduces a bug.
     *
     * @param  array<array-key, mixed>  $artifact
     */
    private function replay(JourneyRunner $runner, array $artifact): void
    {
        ['seed' => $seed, 'tokens' => $tokens, 'forcedDraws' => $forcedDraws] = $this->parseArtifact($artifact);

        $trail = null;

        try {
            ($this->wrapper)(function () use ($runner, $seed, $tokens, $forcedDraws, &$trail): void {
                $trail = $runner->runTokens($this->journeys, $seed, $tokens, $this->http, $forcedDraws);
            });
        } catch (OrderNotViableException $notViable) {
            throw new InvalidJourneyException(sprintf(
                'The replay trail is not viable: %s is no longer reachable in this order (an after()/when() dependency it relied on may have been removed).',
                $notViable->getMessage(),
            ), 0, $notViable);
        }

        if ($trail instanceof Trail) {
            $this->notify($trail);
        }
    }

    /**
     * Read a RUNABOUT_TRAIL value: JSON, or "@path" to read the JSON from disk.
     *
     * @return array<array-key, mixed>
     */
    private function decodeArtifact(string $raw): array
    {
        if (str_starts_with($raw, '@')) {
            $path = substr($raw, 1);
            $contents = @file_get_contents($path);

            if ($contents === false) {
                throw new InvalidJourneyException(sprintf('Could not read the trail artifact file "%s".', $path));
            }

            $raw = $contents;
        }

        $decoded = json_decode($raw, true);

        if (! is_array($decoded)) {
            throw new InvalidJourneyException('A trail artifact must be JSON like {"seed":123,"steps":[[null,"step name",1]]}.');
        }

        return $decoded;
    }

    /**
     * Validate a decoded artifact into a seed and typed tokens.
     *
     * @param  array<array-key, mixed>  $artifact
     * @return array{seed: int, tokens: list<TrailToken>, forcedDraws: array<int, array<int, int>>}
     */
    private function parseArtifact(array $artifact): array
    {
        $seed = $artifact['seed'] ?? null;
        $steps = $artifact['steps'] ?? null;

        if (! is_int($seed) || ! is_array($steps)) {
            throw new InvalidJourneyException('A trail artifact needs an integer "seed" and a "steps" list.');
        }

        $tokens = [];
        $forcedDraws = [];
        $index = 0;

        foreach ($steps as $step) {
            if (! is_array($step) || ! array_key_exists(0, $step) || ! array_key_exists(1, $step) || ! array_key_exists(2, $step)) {
                throw new InvalidJourneyException('Each trail step must be a [label, step, run] triple.');
            }

            $label = $step[0];
            $name = $step[1];
            $run = $step[2];

            if (($label !== null && ! is_string($label)) || ! is_string($name) || ! is_int($run)) {
                throw new InvalidJourneyException('Each trail step must be [label|null, step string, run int].');
            }

            $tokens[] = new TrailToken($label, $name, $run);

            // Optional fourth element: forced draw values pinned by value shrinking.
            if (array_key_exists(3, $step)) {
                if (! is_array($step[3])) {
                    throw new InvalidJourneyException('A trail step\'s forced draws (element 4) must be a list of integers.');
                }

                $values = [];
                foreach ($step[3] as $value) {
                    if (! is_int($value)) {
                        throw new InvalidJourneyException('A trail step\'s forced draws (element 4) must be a list of integers.');
                    }
                    $values[] = $value;
                }

                $forcedDraws[$index] = $values;
            }

            $index++;
        }

        return ['seed' => $seed, 'tokens' => $tokens, 'forcedDraws' => $forcedDraws];
    }
}
