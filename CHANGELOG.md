# Changelog

All notable changes to `vusys/runabout` are documented here. The format follows [Keep a Changelog](https://keepachangelog.com/en/1.1.0/), and the project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- Journey/Step/Context/Invariant core: define a journey's steps as actions plus assertions, and run them in seeded, randomized-but-deterministic orders with invariants checked after every step.
- Precondition-based ordering engine with `after()` sugar, `when()` preconditions, `repeatable()` steps, and clear deadlock/runaway failures.
- `Step::assertWhen($condition, $then, $otherwise = null)`: a conditional assertion in the spirit of Laravel's `when()` — when the condition holds `$then` must pass, otherwise `$otherwise` must pass (or the step claims nothing when it's omitted).
- Seed derivation per journey and trail index, `RUNABOUT_SEED` replay, and `RUNABOUT_RANDOMIZE=1` fresh-seed exploration for nightly jobs.
- Per-execution teardown stack: `Step::teardown()` and `$ctx->defer()`, run LIFO at the end of the trail, guaranteed on failure and never masking the primary failure.
- Actors and HTTP: register named actors with `$ctx->actingAs($user, 'name')` and make authenticated requests through `$ctx->as('name')->postJson(...)`; the most recent response is available as `$ctx->lastResponse()`.
- Clock control: `$ctx->travelTo()`, `$ctx->travel()`, and `$ctx->travelBack()`, automatically unwound at the end of each trail.
- Trail reset strategies: transaction rollback by default, `resetByTruncating(...tables)` opt-in, and `resetWith()` for bespoke wrappers.
- Built-in invariant library: `Invariants::cachedColumnMatches()`, `Invariants::quotaBalances()`, `Invariants::legalTransitions()`, and `Invariants::trashedLeavesNoLiveChildren()`.
- Execution modes: uniform shuffles, `repeatHeavy()` bias toward repeatable steps, per-step `weight()`, and bounded `exhaustive()` enumeration for small journeys.
- Failure output with the full trail (repeat counts included), the failing step, the seed, and a one-line replay instruction on shuffled trails (canonical failures reproduce by re-running).
- Interleave mode: `$this->interleave($a, $b)->shuffles(15)->run()` merge-shuffles several journey instances (own contexts and actors, shared seed and teardown stack) into one trail with cross-instance invariants — the mode built for tenant-isolation bugs no single journey can expose.
