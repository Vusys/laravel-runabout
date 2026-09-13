# API reference

Every public entry point in one place. Each section links to the page that explains the *why*; this page is for when you already know and want the signature.

Everything a journey author touches lives directly under `Vusys\Runabout\`.

## `RunsJourneys`

The trait mixed into a test case. See [Installation](installation.md).

```php
protected function journey(string|Journey $journey): PendingJourney
protected function interleave(string|Journey ...$journeys): PendingJourney
protected function journeyHttpDriver(): HttpDriver
protected function wrapTrail(Closure $trail): void
```

`journey()` accepts a class-string or an instance; `interleave()` takes instances when each needs its own constructor state. Override `wrapTrail()` to change the reset for every journey the test case runs, and `journeyHttpDriver()` to change how actors reach the app.

## `Journey`

The abstract base class. See [Core concepts](concepts.md).

```php
abstract public function steps(): array               // list<Step>, required
public function actors(): array                       // array<string, Authenticatable>
public function invariants(): array                   // list<Invariant>
public function aroundStep(Closure $execution, Context $context): void
```

Only `steps()` is required. `aroundStep()` wraps every execution belonging to this instance — each step *and* each check of this journey's invariants — and must invoke `$execution`. See [Interleaving journeys](interleaving.md#per-instance-environment-aroundstep).

## `Step`

Built fluently; every method returns the step. See [Defining steps](steps.md).

```php
public static function make(string $name): self
public function act(Closure $fn): self                             // fn (Context): mixed
public function assert(Closure $fn): self                          // fn (Context): mixed — repeatable
public function assertWhen(Closure $condition, Closure $then, ?Closure $otherwise = null): self
public function when(Closure $fn): self                            // fn (Context): bool — repeatable
public function after(string ...$steps): self
public function repeatable(?int $max = null, int $min = 1): self
public function weight(int $weight): self                          // >= 1
public function teardown(Closure $fn): self
```

`assert()` and `when()` accumulate — calling either twice adds a second closure rather than replacing the first. `act()` and `teardown()` hold a single closure each, so a second call replaces it.

`Step` also exposes `name()`, `dependencies()`, `isRepeatable()`, `minRuns()`, `pickWeight()`, `isEnabled()`, and `execute()`, which the runner uses and journeys rarely need.

## `PendingJourney`

The fluent runner returned by `journey()` / `interleave()`. See [Execution modes](execution-modes.md) and [Resetting state](database-resets.md).

```php
// Ordering
public function shuffles(int $count): self                         // default 10
public function repeatHeavy(int $bias = 5): self
public function exhaustive(int $limit = 720): self

// Replay
public function seed(int $seed): self
public function trail(array|string $artifact): self                // array, JSON, or "@path.json"

// Observation
public function onTrail(Closure $callback): self                   // fn (Trail): void — repeatable

// Resetting
public function resetWith(Closure $wrapper): self                  // fn (Closure $trail): void
public function resetByTruncating(string ...$tables): self
public function resetConnections(string ...$connections): self
public function resetExternal(Closure $cleanup): self              // repeatable

public function run(): void
```

`onTrail()` and `resetExternal()` accumulate; the mode setters and `resetWith()` overwrite. `resetConnections()` and `resetExternal()` compose in either order — using `resetExternal()` alone still transacts the default connection. Passing no arguments to `resetConnections()` transacts just the default connection.

Modes are not additive: exactly one runs, in the precedence order given under [Environment variables](environment.md#precedence).

## `Context`

The per-trail state bag every closure receives. See [The context](context.md).

```php
// Memory
public function remember(string $key, mixed $value): mixed         // returns the value
public function get(string $key, mixed $default = null): mixed
public function instance(string $key, string $class): object       // typed; throws on mismatch
public function integer(string $key): int
public function string(string $key): string
public function push(string $key, mixed $value): array             // appends, returns the list
public function list(string $key): array                           // [] when absent
public function has(string $key): bool
public function forget(string $key): void

// Seeded randomness — the only sanctioned source
public function randomInt(int $min, int $max): int
public function pick(array $options): mixed
public function randomizer(): Randomizer                           // escape hatch; marks the execution value-opaque

// Run history
public function timesRan(string $step): int
public function ranBefore(string $step): bool

// Actors
public function actingAs(Authenticatable $user, string $name, array $session = []): Actor
public function as(string $name): Actor
public function lastResponse(): TestResponse

// Clock
public function travelTo(DateTimeInterface|string $moment): void
public function travel(string $modifier): void
public function travelBack(): void

// Cleanup
public function defer(Closure $fn): void                           // LIFO at end of trail
```

## `Invariant`

```php
public static function make(string $name, Closure $check): self    // fn (Context): void
public function fromStart(): self                                  // also check before the first step
public function name(): string
public function checksAtStart(): bool
public function check(Context $context): void
```

The check closure signals a violation by throwing — a failed PHPUnit assertion counts. See [Invariants](invariants.md).

## `Invariants`

Static factories, each returning an ordinary `Invariant`. See [Built-in invariants](invariants.md#built-in-invariants).

```php
public static function cachedColumnMatches(
    string $model,
    string $column,
    Closure $expected,                    // fn (TModel): mixed
): Invariant

public static function quotaBalances(
    string $model,
    string $column,
    int|Closure $starting,                // constant, or fn (TModel): int
    Closure $spent,                       // fn (TModel): int
): Invariant

public static function legalTransitions(
    string $model,
    string $column,
    array $transitions,                   // array<string, list<string>>
    ?array $initial = null,               // list<string>|null
    ?Closure $stateOf = null,             // fn (TModel): string|BackedEnum
): Invariant

public static function trashedLeavesNoLiveChildren(
    string $model,
    Closure $liveChildren,                // fn (TModel): int
    string $childrenDescription = 'children',
    string $deletedAtColumn = 'deleted_at',
): Invariant

public static function uniqueBy(
    string $model,
    array $columns,                       // non-empty-list<string>
): Invariant
```

## `Actor`

Returned by `$ctx->actingAs()` and `$ctx->as()`. See [Actors & HTTP](actors-http.md).

```php
public function name(): string
public function user(): Authenticatable
public function withSession(array $session): self                  // a copy with extra session data

public function get(string $uri, array $headers = []): TestResponse
public function post(string $uri, array $data = [], array $headers = []): TestResponse
public function put(string $uri, array $data = [], array $headers = []): TestResponse
public function patch(string $uri, array $data = [], array $headers = []): TestResponse
public function delete(string $uri, array $data = [], array $headers = []): TestResponse

public function getJson(string $uri, array $headers = []): TestResponse
public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
public function putJson(string $uri, array $data = [], array $headers = []): TestResponse
public function patchJson(string $uri, array $data = [], array $headers = []): TestResponse
public function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse
```

Every request authenticates as the actor's user and applies its session data first. The response is also recorded as the context's `lastResponse()`.

## `Trail`

One concrete execution, handed to `onTrail()` callbacks. See [Trails & coverage](observability.md).

```php
public function seed(): int
public function mode(): string          // canonical|shuffled|repeat-heavy|exhaustive|replayed
public function isShuffled(): bool      // true for every mode except canonical
public function steps(): array          // list<string>, labelled step names in order
public function tokens(): array         // list<TrailToken>, the replayable view
public function artifact(): array       // {seed, steps} — the RUNABOUT_TRAIL structure
public function describe(bool $markLast = true): string
public function drawsAt(int $index): array
public function isOpaqueAt(int $index): bool
```

## `TrailCoverage`

Aggregates trails into the summary `RUNABOUT_COVERAGE=1` prints. See [Collecting coverage yourself](observability.md#collecting-coverage-yourself-trailcoverage).

```php
public function record(Trail $trail): void                         // pass to onTrail()
public function trails(): int
public function distinctOrderings(): int
public function stepRuns(): array                                  // array<string, int>
public function timesBefore(string $before, string $after): int
public function unseenPairs(): array                               // list<array{string, string}>
public function describe(): string
```

## Exceptions

All under `Vusys\Runabout\Exceptions\`.

| Exception | Meaning |
|---|---|
| `JourneyFailedException` | A trail failed. Carries the failing `trail()` and renders the trail listing, seed, and replay line. Wraps the original assertion or invariant error as its `previous`. |
| `InvariantViolationException` | An invariant threw. Exposes readonly `$invariant` and `$step` naming both sides. Surfaces as the `previous` of a `JourneyFailedException`. |
| `InvalidJourneyException` | The journey definition itself is broken — duplicate names, unknown `after()` target, deadlock, runaway, an `aroundStep()` that didn't run the execution. See [Troubleshooting](troubleshooting.md). |
| `OrderNotViableException` | Internal. A forced order reached a step that wasn't eligible; the ordering is skipped rather than failed. |
