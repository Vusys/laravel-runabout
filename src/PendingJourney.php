<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Generator;
use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use PHPUnit\Util\ExcludeList;
use Throwable;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Exceptions\OrderNotViableException;
use Vusys\Runabout\Execution\JourneyRunner;
use Vusys\Runabout\Randomness\Draw;
use Vusys\Runabout\Replay\TrailArtifact;
use Vusys\Runabout\Replay\TrailToken;
use Vusys\Runabout\Shrinking\FailureSignature;
use Vusys\Runabout\Shrinking\SequenceShrinker;
use Vusys\Runabout\Shrinking\ValueShrinker;
use Vusys\Runabout\Support\Environment;
use Vusys\Runabout\Support\FrameworkState;
use Vusys\Runabout\Support\RollBack;
use Vusys\Runabout\Support\TrailReporter;

/**
 * Fluent executor returned by RunsJourneys::journey(). Runs the canonical
 * order plus N seeded shuffles, each wrapped so state resets between trails.
 */
final class PendingJourney
{
    private static bool $framesHidden = false;

    private int $shuffles = 10;

    private ?int $seed = null;

    private int $repeatBias = 1;

    private int $minLength = 0;

    private bool $isolatedTrails = false;

    /** @var list<Closure(): void> App-specific resets run after every trail (RunsJourneys::journeyStateResets()). */
    private array $stateResets = [];

    /** @var array{'array cache entries': int, 'Context keys': int}|null Framework state at the start of the first trail, for RUNABOUT_LEAK_CHECK. */
    private ?array $firstFingerprint = null;

    /** Trails started so far in this run, including the one running now. */
    private int $trailsRun = 0;

    private bool $leakReported = false;

    private ?int $exhaustiveLimit = null;

    /** @var array<array-key, mixed>|null A seed + token list to replay verbatim instead of shuffling. */
    private ?array $trailArtifact = null;

    /** @var list<Closure(Trail): void> */
    private array $onTrail = [];

    /** @var list<string|null>|null Connections to wrap in a rolled-back transaction; null until resetConnections() is called. */
    private ?array $transactedConnections = null;

    /** @var list<string|null> Connections whose trail transaction the current reset relies on; none for resetWith()/resetByTruncating(). */
    private array $resetConnections = [];

    /** @var list<Closure(): void> Cleanups run after each trail, for non-transactional external stores. */
    private array $externalResets = [];

    /** @var non-empty-list<Journey> */
    private readonly array $journeys;

    /**
     * @param  Journey|list<Journey>  $journey  One journey, or several to interleave into each trail.
     * @param  Closure(Closure): void  $wrapper  Wraps each trail; the default (from RunsJourneys) rolls back a database transaction.
     * @param  (Closure(): void)|null  $countAssertions  Adds passing invariant checks to the test case's assertion count (RunsJourneys passes addToAssertionCount()).
     */
    public function __construct(
        Journey|array $journey,
        private Closure $wrapper,
        private ?HttpDriver $http = null,
        private readonly ?Closure $countAssertions = null,
    ) {
        $journeys = is_array($journey) ? $journey : [$journey];

        if ($journeys === []) {
            throw new InvalidJourneyException('At least one journey is required.');
        }

        $this->journeys = $journeys;
    }

    /**
     * @internal Fail the step that ends the trail transaction on these
     * connections (null for the default). RunsJourneys turns this on for its
     * transactional reset; resetWith() turns it off again.
     *
     * @param  list<string|null>  $connections
     */
    public function watchingTransactionsOn(array $connections): self
    {
        $this->resetConnections = $connections;

        return $this;
    }

    /**
     * Reset framework state a database rollback doesn't reach after every
     * trail, so no trail depends on what an earlier one left behind: in-memory
     * (array) cache stores, which hold cached values and rate-limiter
     * counters; Laravel Context and scoped container instances; and every
     * auth guard's login. Only array cache stores are flushed, never a real
     * one. Shrink and replay runs are reset the same way.
     */
    public function isolateTrails(): self
    {
        $this->isolatedTrails = true;

        return $this;
    }

    /**
     * @internal Resets the test case asks for after every trail, from
     * RunsJourneys::journeyStateResets().
     *
     * @param  list<Closure(): void>  $resets
     */
    public function withStateResets(array $resets): self
    {
        $this->stateResets = $resets;

        return $this;
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
        $this->trailArtifact = is_string($artifact) ? TrailArtifact::decode($artifact) : $artifact;

        return $this;
    }

    /**
     * Authenticate every actor request as a freshly loaded user, as production
     * resolves it from the session. By default an actor reuses one user
     * instance for the whole run, so relations and caches loaded on it go
     * stale when another actor changes them. A deleted user's requests go out
     * as a guest. Costs one query per request; users that aren't saved
     * Eloquent models are authenticated as they are.
     */
    public function freshActors(): self
    {
        $this->http = $this->http?->withFreshUsers();

        return $this;
    }

    /**
     * Make each actor request behave like a fresh production request. Before
     * every request, Laravel Context and scoped container instances are
     * cleared (in a test process they otherwise outlive the request that set
     * them), and the actor's user is reloaded, as freshActors() does.
     */
    public function isolateRequests(): self
    {
        $this->http = $this->http?->withIsolatedRequests();

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
     * Keep shuffled trails going until they hold at least $executions step
     * executions, not just until every step's minimum is met — or until no
     * step is eligible. Journeys made mostly of optional() steps otherwise
     * stop as soon as their few required steps have run, and the shuffles
     * collapse into a handful of short, repeated orderings. Zero or less
     * means no minimum length.
     */
    public function minLength(int $executions): self
    {
        $this->minLength = $executions;

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
        $this->resetConnections = [];

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
        $this->resetConnections = $connections;

        $this->wrapper = function (Closure $trail) use ($connections, $externalResets): void {
            foreach ($connections as $name) {
                DB::connection($name)->beginTransaction();
            }

            try {
                RollBack::after($trail, array_map(fn (?string $name): Connection => DB::connection($name), array_reverse($connections)));
            } finally {
                foreach ($externalResets as $cleanup) {
                    $cleanup();
                }
            }
        };

        return $this;
    }

    /**
     * Run the journey. When RUNABOUT_COVERAGE is set, every completed trail is
     * also collected into an aggregate summary printed to STDERR once the run
     * finishes — a failing run throws before that point and reports its failure
     * instead, so nothing is printed.
     */
    public function run(): void
    {
        $this->hideOwnFramesFromPhpunit();

        $coverage = TrailReporter::coverage($this->journeys);

        if ($coverage instanceof TrailCoverage) {
            $this->onTrail($coverage->record(...));
        }

        $this->execute();

        if ($coverage instanceof TrailCoverage) {
            TrailReporter::printCoverage($this->names(), $coverage);
        }
    }

    /**
     * A failure is a chain of wrappers around the user's assertion, and PHPUnit
     * prints every link's trace. Excluding this package's frames, as PHPUnit
     * already does for vendor code, leaves each trace pointing at the journey
     * and test lines. getTrace() itself is untouched.
     *
     * @infection-ignore-all Runs once per process, so only the suite's first journey test covers it, and Infection runs only that test.
     */
    private function hideOwnFramesFromPhpunit(): void
    {
        if (self::$framesHidden || ! class_exists(ExcludeList::class)) {
            return;
        }

        ExcludeList::addDirectory(__DIR__);
        self::$framesHidden = true;
    }

    private function execute(): void
    {
        $runner = new JourneyRunner($this->countAssertions, $this->resetConnections);

        $exhaustiveLimit = $this->exhaustiveLimit;

        if ($exhaustiveLimit !== null) {
            $this->registerVerbosePrinter(total: null);
            $this->runExhaustive($runner, $exhaustiveLimit);

            return;
        }

        $environmentTrail = Environment::trail();
        $artifact = $this->trailArtifact ?? ($environmentTrail === null ? null : TrailArtifact::decode($environmentTrail));

        if ($artifact !== null) {
            $this->registerVerbosePrinter(total: 1);
            $this->replay($runner, $artifact);

            return;
        }

        $replaySeed = $this->seed ?? Environment::seed();

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
        $this->trailsRun++;
        $this->checkForLeaks();
        $trail = null;

        try {
            $this->resetAround(function () use ($runner, $seed, $shuffle, &$trail): void {
                $trail = $runner->runInterleaved($this->journeys, $seed, $shuffle, $this->http, $this->repeatBias, $this->minLength);
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
     * same failure, and re-frame the exception around it. Off for structural
     * failures (deadlocks, runaways, broken journeys — no meaningful
     * subsequence), and skippable with RUNABOUT_SHRINK=0.
     */
    private function shrink(JourneyRunner $runner, JourneyFailedException $failure): JourneyFailedException
    {
        if (! Environment::shrinkingEnabled() || ! in_array($failure->trail()->mode(), ['canonical', 'shuffled', 'repeat-heavy'], true)) {
            return $failure;
        }

        if ($failure->getPrevious() instanceof InvalidJourneyException) {
            return $failure;
        }

        $original = $failure->trail();
        $tokens = $original->tokens();
        [$minimal, $tokens, $forced] = $this->minimise($runner, $failure, $tokens);

        // State can only have leaked from an earlier trail of this run.
        if ($this->trailsRun < 2) {
            return $minimal;
        }

        $leak = $this->diagnoseLeak($runner, $original->seed(), $tokens, $forced);

        return $leak === null ? $minimal : $minimal->withNote($leak);
    }

    /**
     * Minimise a failing trail: the shortest token list that reproduces the
     * same failure, then the smallest drawn values inside it.
     *
     * @param  list<TrailToken>  $tokens
     * @return array{JourneyFailedException, list<TrailToken>, array<int, array<int, int>>} The failure to report, and the tokens and forced draws that reproduce it.
     */
    private function minimise(JourneyRunner $runner, JourneyFailedException $failure, array $tokens): array
    {
        $original = $failure->trail();
        $count = count($tokens);

        if ($count < 2) {
            return [$failure, $tokens, []];
        }

        $signature = FailureSignature::from($failure);
        $seed = $original->seed();

        // Length first: reduce to the shortest reproducing subsequence.
        $result = (new SequenceShrinker(
            fn (array $positions): bool => $this->candidateReproduces($runner, $seed, $this->tokensAt($tokens, $positions), $signature),
            Environment::shrinkBudget(),
        ))->shrink(range(0, $count - 1));

        $shrunkTokens = $this->tokensAt($tokens, $result['positions']);
        $sequenceReduced = count($shrunkTokens) < $count;

        // Then values: minimise the draws inside the now-minimal trail.
        $value = $this->valueShrink($runner, $seed, $shrunkTokens, $signature);
        $valueReduced = $value['forced'] !== [];

        $replay = $sequenceReduced || $valueReduced ? $this->replayFailure($runner, $seed, $shrunkTokens, $value['forced']) : null;

        if ($replay instanceof JourneyFailedException) {
            return [JourneyFailedException::shrunk($replay, $count, $seed, $result['replays'] + $value['replays'], $original->mode() === 'canonical'), $shrunkTokens, $value['forced']];
        }

        return [$failure, $tokens, []];
    }

    /**
     * Run a trail inside the reset wrapper, then the framework and app resets
     * that apply. A reset that fails never masks the trail's own failure.
     *
     * @param  Closure(): void  $trail
     */
    private function resetAround(Closure $trail): void
    {
        $resets = $this->stateResets;

        if ($this->isolatedTrails) {
            array_unshift($resets, FrameworkState::flushArrayCaches(...), FrameworkState::flushRequestState(...), FrameworkState::forgetGuards(...));
        }

        if ($resets === []) {
            ($this->wrapper)($trail);

            return;
        }

        $failure = null;

        try {
            ($this->wrapper)($trail);
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        foreach ($resets as $reset) {
            try {
                $reset();
            } catch (Throwable $caught) {
                $failure ??= $caught;
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }

    /**
     * Replays run in the same process as the trails before them, so state a
     * database rollback doesn't reach (cache, Context, guards, actor user
     * objects) can make a short trail fail only because of what ran earlier.
     * Replay the minimal trail after clearing each in turn: if it then
     * passes, say so, because its artifact may not reproduce on its own.
     *
     * @param  list<TrailToken>  $tokens
     * @param  array<int, array<int, int>>  $forcedDraws
     */
    private function diagnoseLeak(JourneyRunner $runner, int $seed, array $tokens, array $forcedDraws): ?string
    {
        $probes = [
            ['flushing the array cache store', 'Cached values or rate-limiter counters are leaking between trails; call isolateTrails() to flush them after each trail.', FrameworkState::flushArrayCaches(...), false],
            ['clearing Laravel Context and scoped instances', 'Request-scoped state is leaking between requests; call isolateRequests() to clear it before each request.', FrameworkState::flushRequestState(...), false],
            ['logging out every auth guard', 'A login is leaking between trails; call isolateTrails() to log every guard out after each trail, and authenticate through actors.', FrameworkState::forgetGuards(...), false],
            ['reloading the actors\' users', 'An actor\'s user object carries state from earlier trails; call freshActors().', null, true],
        ];

        foreach ($probes as [$cleared, $advice, $clear, $freshUsers]) {
            if ($freshUsers && ! $this->http instanceof HttpDriver) {
                continue;
            }

            if ($clear !== null) {
                $clear();
            }

            if (! $this->reproducesWith($runner, $seed, $tokens, $forcedDraws, $freshUsers ? $this->http->withFreshUsers() : $this->http)) {
                return sprintf(
                    'The minimal trail passed when replayed after %s, so the failure depends on state that earlier trails or replays left behind, and the replay line above may not reproduce it on its own. %s',
                    $cleared,
                    $advice,
                );
            }
        }

        return null;
    }

    /**
     * @param  list<TrailToken>  $tokens
     * @param  array<int, array<int, int>>  $forcedDraws
     */
    private function reproducesWith(JourneyRunner $runner, int $seed, array $tokens, array $forcedDraws, ?HttpDriver $http): bool
    {
        try {
            $this->resetAround(function () use ($runner, $seed, $tokens, $forcedDraws, $http): void {
                $runner->runTokens($this->journeys, $seed, $tokens, $http, $forcedDraws);
            });

            return false;
        } catch (JourneyFailedException) {
            return true;
        } catch (OrderNotViableException) {
            // No longer viable once the state was cleared: it depended on it too.
            return false;
        }
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
            Environment::shrinkBudget(),
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
            $this->resetAround(function () use ($runner, $seed, $tokens): void {
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
            $this->resetAround(function () use ($runner, $seed, $candidate, $forcedDraws): void {
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
            $this->resetAround(function () use ($runner, $seed, $tokens, $forcedDraws): void {
                $runner->runTokens($this->journeys, $seed, $tokens, $this->http, $forcedDraws);
            });
        } catch (JourneyFailedException $failure) {
            return $failure;
        } catch (OrderNotViableException|InvalidJourneyException) {
            return null;
        }

        return null;
    }

    /**
     * RUNABOUT_LEAK_CHECK: compare framework state at the start of each trail
     * with the start of the first, and warn once when a trail starts with
     * state an earlier one left behind.
     */
    private function checkForLeaks(): void
    {
        if (! Environment::leakCheckEnabled() || $this->leakReported) {
            return;
        }

        $now = FrameworkState::fingerprint();
        $this->firstFingerprint ??= $now;
        $grown = [];

        foreach ($now as $what => $count) {
            $before = $this->firstFingerprint[$what];

            if ($count > $before) {
                $grown[] = sprintf('%s %d → %d', $what, $before, $count);
            }
        }

        if ($grown !== []) {
            $this->leakReported = true;
            TrailReporter::write(sprintf(
                "Runabout leak check (%s): trail %d started with state earlier trails left behind: %s. A rollback doesn't reset this, so trails can depend on what ran before them.\n",
                $this->names(),
                $this->trailsRun,
                implode('; ', $grown),
            ));
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
        $printer = TrailReporter::verbosePrinter($this->names(), $total);

        if ($printer instanceof Closure) {
            $this->onTrail[] = $printer;
        }
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
                $this->resetAround(function () use ($runner, $journey, $order, $seed, &$trail): void {
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
        if (Environment::randomizeEnabled()) {
            return random_int(0, 2147483647);
        }

        return crc32(implode('+', array_map(fn (Journey $journey): string => $journey::class, $this->journeys)).'#'.$index);
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
        $parsed = TrailArtifact::parse($artifact);

        $trail = null;

        try {
            $this->resetAround(function () use ($runner, $parsed, &$trail): void {
                $trail = $runner->runTokens($this->journeys, $parsed->seed, $parsed->tokens, $this->http, $parsed->forcedDraws);
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
}
