# Changelog

All notable changes to `vusys/laravel-runabout` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- A failed step that made an actor request now ends its failure message with the last response it received: the request's method and URI, the status, a redirect's target, and the validation errors flashed to the session or the start of the body. Most failed step assertions in an HTTP journey mean the app rejected the request for an unexpected reason, and finding out which used to mean adding debug output to the step and replaying. Invariant failures, and steps that made no request, are unchanged. ([#70](https://github.com/Vusys/laravel-runabout/issues/70))
- A step that ends the trail's database transaction now fails with an explanation. On MySQL and MariaDB a schema change inside a step commits implicitly, so the trail's rows could no longer be rolled back, and the run used to fail at reset time with a bare `SAVEPOINT trans2 does not exist` that named neither the cause nor the step. With the default reset, `resetConnections()` or `resetExternal()`, Runabout now checks the transaction after every step and fails the step that ended it, pointing at `resetByTruncating(...)`. The failure is structural, so it isn't shrunk. A rollback that fails at reset no longer masks the trail's own failure, and on its own it fails with the same explanation. Resets supplied with `resetWith()` aren't checked. ([#69](https://github.com/Vusys/laravel-runabout/issues/69))

### Changed

- Each passing invariant check now counts as one PHPUnit assertion. Built-in invariants signal a violation by throwing, so a journey checked only by invariants performed "no assertions", and PHPUnit reported it as risky even after thousands of checks. `RunsJourneys::journeyAssertionCounter()` supplies the counter; override it to return `null` to keep the old count. Trails and seeds are unchanged. ([#71](https://github.com/Vusys/laravel-runabout/issues/71))

### Fixed

- A request an invariant makes through an actor no longer replaces `lastResponse()`. An invariant that asks "what does this actor see?" made the next step's `lastResponse()` return the invariant's response, so a step that asserted on the previous step's response without making a request of its own checked the wrong one. Requests made while invariants are checked, including `fromStart()` baseline checks, are still returned to the invariant, and are no longer recorded. ([#78](https://github.com/Vusys/laravel-runabout/issues/78))

## [1.3.0] - 2026-10-17

Actors that behave more like production users, and a coverage report without false gaps. Actors can log in on a named guard, `freshActors()` reloads their users before each request, and `RUNABOUT_COVERAGE` leaves out pairs `after()` makes impossible. Every 1.2 seed and `RUNABOUT_TRAIL` artifact replays unchanged.

### Added

- `PendingJourney::freshActors()` reloads each actor's user before every request, as production loads it from the session. An actor reuses one user instance for the whole run, so a permissions list that the app cached on the instance went stale. An admin granted a member's role a permission, but the app still refused the member's next request. In the reverse case, a revoked user keeps acting. Runabout reloads a saved Eloquent user with `fresh()`, at one query per request. A deleted user makes its requests as a guest. The option is off by default, so outcomes and trails do not change. ([#51](https://github.com/Vusys/laravel-runabout/issues/51))
- Actors can log in on a named guard: `'admin' => [$user, 'admin']` in `actors()`, or `$ctx->actingAs($user, 'admin', guard: 'admin')`. Before, every actor logged in on the default guard. In an app with a separate `admin` guard, the app redirected an admin actor to login. A call to `actingAs($admin, 'admin')` by hand made `admin` the default guard for the rest of the test. A later storefront step then created a cart for the admin. Runabout now sets a named-guard actor on its guard only. Once a named guard is in play, Runabout resets the default guard before each request. It also logs out the other actors' guards, as separate production sessions would. Journeys whose actors all use the default guard behave as before. ([#53](https://github.com/Vusys/laravel-runabout/issues/53))

### Changed

- The `RUNABOUT_COVERAGE` summary leaves out the step pairs that `after()` makes impossible. The summary always listed these pairs as never observed, and they buried the real gaps. For example, a journey with one setup step that every other step follows reported `8 of 56` pairs unseen, but 7 of the 8 could never happen. The summary now leaves out a pair when one step is after another, directly or transitively, and that other step runs at most once. Pairs of steps from different interleaved instances still count. The header gives the number it left out: `(1 of 49, 7 excluded by after())`. To get the same result from `TrailCoverage`, pass the journeys to its constructor. Trails and seeds do not change. ([#44](https://github.com/Vusys/laravel-runabout/issues/44))

## [1.2.0] - 2026-10-16

Failure reports that need less decoding: canonical failures are shrunk, the output has fewer repeated messages and Runabout frames, and a shrunk trail names what each `pick()` picked. Every 1.1 seed and `RUNABOUT_TRAIL` artifact replays unchanged.

### Changed

- A failure in the canonical (declared) order is now shrunk like a shuffled one, and replays as a `RUNABOUT_TRAIL` artifact. The declared order runs every step, so it is often the first trail to fail, and a 7-step canonical failure used to leave the reader to bisect it by hand. The headline reads `Shrunk the canonical order from N executions to M`, and the last line says to re-run the journey for the full trail, since a seed would replay a shuffled trail instead. Trails and seeds are unchanged; `RUNABOUT_SHRINK=0` still turns shrinking off. ([#41](https://github.com/Vusys/laravel-runabout/issues/41))
- Failure output is about half as long. PHPUnit printed the failed assertion's message three times, under three stack traces made mostly of Runabout's own frames, so the line in the journey that failed was easy to miss. Runabout now adds its `src/` directory to PHPUnit's trace exclude list, as PHPUnit does for vendor code, and `InvariantViolationException`'s message names the invariant and step without repeating its cause. The `JourneyFailedException` message is unchanged, the exception chain is unchanged, and `getTrace()` still returns every frame. ([#43](https://github.com/Vusys/laravel-runabout/issues/43))
- A shrunk trail's listing names what each `pick()` picked: `invoice [drew 1 → Order #140, 0 → LineItem #212, 1]` instead of `[drew 1, 0, 1]`. Models show their class basename and key, enums their case, and scalars their value; anything else, and every `randomInt()`, still shows only the number. Decoding a pick used to mean replaying the artifact and adding state to the failure message. Draws, seeds and `RUNABOUT_TRAIL` artifacts are unchanged. ([#52](https://github.com/Vusys/laravel-runabout/issues/52))

## [1.1.0] - 2026-10-15

Driven by journey-testing a large real application (BookStack), which surfaced journeys the 1.0 step model could not express. Every 1.0 seed and `RUNABOUT_TRAIL` artifact replays unchanged.

### Added

- `Step::optional()`, and `repeatable(min: 0)`: a step the trail may finish without. Real apps have branches that close each other off — converting a BookStack book to a shelf destroys its chapters, so a "delete chapter" step can never run afterwards — and a required step there deadlocked every trail that took the other branch first. An optional step that is not eligible when reached in declared order or in an exhaustive ordering is skipped instead of failing the trail.
- `PendingJourney::minLength(int $executions)`: shuffled trails keep picking eligible steps until they are at least that long, ending early only when nothing is eligible. Without it, a journey of mostly optional steps stops as soon as its required steps have run — a BookStack comments journey produced 14 distinct orderings from 26 trails. The default of 0 leaves every existing seed's trail unchanged.

### Changed

- `repeatable()`'s `$min` now defaults to `null`, meaning "keep the current minimum", so `->optional()->repeatable(max: 3)` stays optional. Behaviour is unchanged for steps that never call `optional()`.

### Fixed

- A shrink that removed no executions but minimised drawn values was headed "Shrunk from N executions to N", which read like a bug. It now says `Shrunk drawn values; all N executions are needed (R replays):`. ([#42](https://github.com/Vusys/laravel-runabout/issues/42))
- Interleaving more than 26 journeys labelled the 27th instance `[`, then `\` and so on, and labels repeated after 191 instances because `chr()` wraps. Labels now continue spreadsheet-style (`AA`, `AB`, …). `A`–`Z` are unchanged, so every existing seed and `RUNABOUT_TRAIL` artifact replays identically. This also clears the PHPStan `chr()` error reported on PHP 8.5. ([#45](https://github.com/Vusys/laravel-runabout/issues/45))

## [1.0.0] - 2026-09-30

The first stable release. There are no behaviour changes since 0.2.0; this release commits to the public API it already had.

### Changed

- The public API is now covered by Semantic Versioning. [API reference → Stability](https://vusys.github.io/laravel-runabout/api-reference/#stability) spells out what that covers: the root `Vusys\Runabout\` classes and `Exceptions\*`, the `TrailToken` and `Draw` values that `Trail` hands out, the `RUNABOUT_*` environment variables, and replay, so a seed or `RUNABOUT_TRAIL` artifact from any 1.x release replays the same trail on every later 1.x release.
- The `Execution\`, `Randomness\`, `Shrinking\`, `Replay\`, and `Support\` namespaces are internal. Their classes are now marked `@internal`, as are `Trail::record()` and `Trail::attachDraws()`, which only the runner calls.

## [0.2.0] - 2026-08-24

### Changed

- `src/` is now split by concern instead of being one flat namespace. Everything a journey author touches keeps its exact fully-qualified name at `Vusys\Runabout\` — `Journey`, `Step`, `Context`, `Invariant`, `Invariants`, `RunsJourneys`, `PendingJourney`, `Actor`, `Trail`, `TrailCoverage`, `HttpDriver`, and `Exceptions\*` are all untouched — so journeys, docs, and existing imports need no changes. The machinery behind them moved into namespaces that say what it is: `Execution\` (`JourneyRunner`, `JourneyInstance`, `DeferredStack`), `Randomness\` (`Draw`, `DrawSource`, `StreamDrawSource`, `ScriptedDrawSource`, `SeedSchema`), `Shrinking\` (`SequenceShrinker`, `ValueShrinker`, `FailureSignature`), `Replay\` (`TrailToken`, `TrailArtifact`), and `Support\` (`Environment`, `TrailReporter`). The root directory is now the package's public surface and nothing else, which is the contract the flat layout could not express. Behaviour is unchanged throughout; the existing suite passes untouched apart from imports.
- `TrailShrinker` is now `Shrinking\SequenceShrinker`, so it reads as a sibling of `ValueShrinker` — the two are the same algorithm over sequence positions and over drawn values, and the old name described what it shrank a trail *of* rather than what it shrinks.

### Added

- `Randomness\SeedSchema`: seed schema v2's five stream derivations, which were private methods on `JourneyRunner`, are now a class of their own. The schema is a compatibility surface — changing any string built there re-keys every existing artifact — so it is worth being able to point at, and `SeedDerivationTest` now pins the two properties that matter (each stream is a pure function of the execution's identity; the picker, teardown, baseline, and execution streams are all distinct).
- `Replay\TrailArtifact`: the `RUNABOUT_TRAIL` wire format now has one owner. Encoding lived on `Trail` and decoding plus validation lived on `PendingJourney`, so a change to the format meant editing two unrelated classes at opposite ends of the package; both halves of the contract now sit together.
- `Support\Environment`: every `RUNABOUT_*` variable a run responds to is read in one place, matching what `docs/environment.md` documents. The existing asymmetry is preserved and now documented rather than incidental — `RUNABOUT_SHRINK` is an opt-*out* where only the exact string `"0"` disables shrinking, while every other flag is an opt-*in* where unset, empty, and `"0"` all read as off.
- `Support\TrailReporter`: the two STDERR reporting concerns (`RUNABOUT_VERBOSE`'s per-trail log and `RUNABOUT_COVERAGE`'s end-of-run summary) moved out of the fluent executor.

## [0.1.1] - 2026-08-08

### Fixed

- Interleaved canonical order: `runCanonical` ran each instance's whole journey before starting the next, so the canonical trail never interleaved and a step gated on another instance's state (`only read B's records if B has any`) could never be enabled — the run failed immediately with a message that sent you to check `when()`/`after()` constraints that were correct. The canonical order now runs round robin across instances by declared position (A's first step, B's first step, A's second, and so on), keeping each instance's own order intact while making the canonical trail an actual interleaving.

## [0.1.0] - 2026-07-24

### Changed

- `Invariants::legalTransitions()`: the "not a legal initial state" error now points at the fix for the common cause — a row that exists before the journey and is transitioned by the first step, so the invariant never observes its true initial state. Record it in a leading step.

### Added

- Value shrinking: after a failing trail is minimised in length, its drawn values are minimised too, so the counterexample is concrete — not "open two deals of random amounts and close the larger" but "open a deal of 51, open a deal of 50, close the larger". Each `randomInt`/`pick` draw is pushed toward the low end of its domain (ints toward `min`, picks toward the first option) by a budgeted, deterministic binary search gated by the same same-failure oracle as sequence shrinking, so it stops at the boundary that still reproduces. Pinned values ride along in the `RUNABOUT_TRAIL` artifact's optional fourth element (existing artifacts stay valid) and show in the failure trail (`open opportunity [drew 1, 51]`). A step that reaches for the raw `$ctx->randomizer()` is left value-opaque and skipped.
- `Invariants::uniqueBy($model, $columns)`: assert no two rows share a column tuple — the guarantee a unique constraint or a firstOrCreate/dedup path is meant to provide. Catches duplicate rows a missing or wrongly-scoped key lets through ("one metric per report", "one leaderboard period per (tenant, start)"). Reads the default scope, so soft-deleted rows may legitimately repeat a live key.
- `Invariant::fromStart()`: check an invariant once at the start of the trail, before any step runs, so it observes the world's baseline. The motivating case is a state invariant (`Invariants::legalTransitions()`) on a row that exists before the journey — without a baseline observation the invariant first sees the row *after* the opening step, mistaking that step's transition for the initial state. Opt-in per invariant, so existing journeys are unchanged.
- `Invariants::quotaBalances()` accepts a per-row starting allowance: `$starting` may now be a `Closure(TModel): int` as well as an `int`, so quotas that differ by plan, tier, or tenant (the common case) can be expressed without a constant. The existing `int` form is unchanged.
- Automatic trail shrinking: when a shuffled or repeat-heavy trail fails, Runabout minimises it to the shortest subsequence that still reproduces the *same* failure and leads the failure output with that — "shrunk from 23 executions to 5". It is delta-debugging (coarse chunk removal, then a single-execution polish sweep) with a same-failure oracle (same exception class plus the invariant's or failing step's labelled name; message text excluded, so a candidate that trips a *different* bug is rejected rather than reported). Deterministic, budget-capped (default 200 candidate replays, `RUNABOUT_SHRINK_BUDGET` to change), off for canonical and structural failures, and disableable with `RUNABOUT_SHRINK=0`.
- Explicit-order replay: `PendingJourney::trail($artifact)` and `RUNABOUT_TRAIL` replay one exact trail — a seed plus an ordered token list `[[label, step, run], ...]` — under a new `'replayed'` mode, instead of re-deriving a shuffle from a bare seed. Order travels with the artifact, so it reproduces repeat-heavy and partial (shrunk) trails that `RUNABOUT_SEED` cannot; `RUNABOUT_TRAIL=@path.json` reads a large artifact from disk. This is the substrate the shrinker replays candidates through, and the replay line printed under every shrunk failure.
- Seed schema v2 (position-independent randomness): the trail seed now derives one picker stream (all order decisions) and a fresh data stream per execution, keyed by (seed, instance label, step name, run index). A step's `randomInt()`/`pick()`/`randomizer()` draws therefore depend only on *which* execution it is, never on what ran before it — so a surviving execution reproduces its draws verbatim when the trail is reordered or thinned, which is what makes replay and shrinking sound. A seed still reproduces the whole trail exactly; but because data draws left the shared stream, existing pinned `RUNABOUT_SEED` values re-key (the reason to land this while there are no external users).
- `Journey::actors()`: declare named actors (name => user) once on the journey and Runabout registers them on every trail's context, so HTTP steps can call `$ctx->as('manager')->postJson(...)` without a setup step (actors live on the per-trail context, so they otherwise have to be re-registered each trail). The users must exist before the run; anything created inside a trail is rolled back, so register those with `$ctx->actingAs()` in a step as before.
- Per-actor session: `$ctx->actingAs($user, 'agent', ['tenant' => 5])` attaches session data that rides along with every request the actor makes, and `Actor::withSession([...])` layers more on for a single request — so journeys can drive apps whose tenancy (or other state) lives in the session, through the full middleware stack, without a per-request session dance.
- `PendingJourney::resetConnections(...$connections)` and `resetExternal(Closure $cleanup)`: reset across more than one store between trails — roll back a transaction on each named connection (the multi-connection form of the default reset) and/or run a cleanup for non-transactional stores (a Mongo/Elasticsearch wipe, a cache flush). The two compose; `resetExternal()` alone still transacts the default connection.
- Journey/Step/Context/Invariant core: define a journey's steps as actions plus assertions, and run them in seeded, randomized-but-deterministic orders with invariants checked after every step.
- Precondition-based ordering engine with `after()` sugar, `when()` preconditions, `repeatable()` steps, and clear deadlock/runaway failures.
- `Step::assertWhen($condition, $then, $otherwise = null)`: a conditional assertion in the spirit of Laravel's `when()` — when the condition holds `$then` must pass, otherwise `$otherwise` must pass (or the step claims nothing when it's omitted).
- `Context::push($key, $value)` and `Context::list($key)`: remembered lists without the read-append-write dance — journeys that create several of a thing can accumulate them with one call per step.
- Trail observability: `->onTrail(fn (Trail $trail) => ...)` receives every completed trail in every mode, and `RUNABOUT_VERBOSE=1` prints each one to stderr as it finishes — passing runs are no longer a black box.
- Aggregate coverage: `TrailCoverage` collects completed trails into a summary (executions per step, distinct orderings, and the step-pair orderings no trail ever explored), and `RUNABOUT_COVERAGE=1` prints one to stderr when a run finishes — the direct answer to "is the shuffle count buying coverage?".
- `Journey::aroundStep($execution, $context)`: an overridable per-instance wrapper around every step execution and every check of that journey's invariants — for environment that lives in shared global state but differs per instance, like session-keyed tenancy in interleaved trails. A wrapper that fails to invoke the execution closure is rejected as an invalid journey.
- Interleaved invariant violations now carry both instance labels: the invariant's owning instance and the acting step's (`Invariant "A: ..." violated after step "B: ..."`).
- Seed derivation per journey and trail index, `RUNABOUT_SEED` replay, and `RUNABOUT_RANDOMIZE=1` fresh-seed exploration for nightly jobs.
- Per-execution teardown stack: `Step::teardown()` and `$ctx->defer()`, run LIFO at the end of the trail, guaranteed on failure and never masking the primary failure.
- Actors and HTTP: register named actors with `$ctx->actingAs($user, 'name')` and make authenticated requests through `$ctx->as('name')->postJson(...)`; the most recent response is available as `$ctx->lastResponse()`.
- Clock control: `$ctx->travelTo()`, `$ctx->travel()`, and `$ctx->travelBack()`, automatically unwound at the end of each trail.
- Trail reset strategies: transaction rollback by default, `resetByTruncating(...tables)` opt-in, and `resetWith()` for bespoke wrappers.
- Built-in invariant library: `Invariants::cachedColumnMatches()`, `Invariants::quotaBalances()`, `Invariants::legalTransitions()`, and `Invariants::trashedLeavesNoLiveChildren()`.
- `Invariants::legalTransitions()` handles non-string state columns: a native `BackedEnum` is coerced to its value automatically, and a `stateOf` closure maps any cast value object (such as a spatie ModelState) to its state string — so the helper covers cast state columns without depending on any state-cast library.
- `Step::repeatable(max:, min:)` gains a minimum run count: a single always-enabled "advance" step with `min` greater than 1 drives a bounded, seeded random walk — the shape a state-machine journey needs, which a plain repeatable step (which runs once when it is the only pending step) cannot express. Defaults to `min: 1`, so existing journeys are unchanged.
- Execution modes: uniform shuffles, `repeatHeavy()` bias toward repeatable steps, per-step `weight()`, and bounded `exhaustive()` enumeration for small journeys.
- Failure output with the full trail (repeat counts included), the failing step, the seed, and a one-line replay instruction on shuffled trails (canonical failures reproduce by re-running).
- Interleave mode: `$this->interleave($a, $b)->shuffles(15)->run()` merge-shuffles several journey instances (own contexts and actors, shared seed and teardown stack) into one trail with cross-instance invariants — the mode built for tenant-isolation bugs no single journey can expose.
