# Interleave mode — design

Interleave mode runs two or more journey instances merge-shuffled into a single trail. It exists for the bug class nothing else reaches: cross-actor and cross-tenant contamination, where each participant's journey is individually correct but their interaction leaks state — the classic case being multi-tenant isolation enforced by query convention rather than by the database.

## The core design question

Two journey instances in one trail: do they share a context or get their own? Both answers are partially right, so the design splits the context's responsibilities:

**Per instance** (each journey gets its own):

- **Remembered values.** Instance A's `'post'` must never collide with instance B's `'post'`. Journeys are written against `$ctx->remember()`/`$ctx->get()` with no knowledge of interleaving; namespacing keys would leak the interleave into every journey's code.
- **Run history.** `ranBefore()`/`timesRan()` drive conditional assertions and `after()` constraints, and those are meaningful per instance: B's `cast vote` asserting "first vote" must not see A's votes.
- **Actors and last response.** Tenant A's `'ana'` and tenant B's `'ana'` are different users; a response belongs to the instance whose step made the request.

**Shared** (one per trail):

- **The randomizer.** One seed drives the merged step picker and every instance's data choices, in a single draw sequence. This is what keeps an interleaved trail replayable from one integer.
- **The teardown stack.** Teardowns must unwind in reverse execution order across the *whole merged trail* — if A froze time and then B built state on top of the frozen clock, B's teardown must run before A's. A shared LIFO stack gives that for free.
- **The clock and the HTTP driver.** There is one world: one test clock, one application under test.

So: each instance owns a `Context` (values, history, actors), and all contexts share the trail's `Randomizer` and `DeferredStack`.

## Invariants

After every step — whichever instance it belongs to — the runner checks the union of all instances' invariants. Invariants are world-facing (they query the database, not the context), so a tenant-isolation invariant declared on the journey class naturally polices both instances at once: two instances of the same journey contribute the same checks twice, which is redundant but harmless, and instances of different journeys compose their checks.

## Per-instance environment: aroundStep()

The per-instance/shared split above covers state the *package* owns. Real apps add a third kind: global state the app itself uses to answer "who is acting" — session-keyed tenancy being the canonical case. That state is one-per-world (shared), but its correct value is one-per-instance, and whose turn it is changes step to step, so it cannot be established once per trail.

`Journey::aroundStep(Closure $execution, Context $context)` is the seam: the runner passes every execution belonging to an instance through its journey's override — each step (act + assertions) and each check of that journey's invariants. Invariants are deliberately included: they run after *other* instances' steps, so they would otherwise observe whatever environment the last act left behind. Teardowns are deliberately excluded: they run as one interleaved LIFO sequence at trail end, and a deferred closure that needs instance environment can establish it itself.

Declaring the wrapper once on the journey (rather than calling a helper at the top of every act) is a correctness decision, not an ergonomic one. A forgotten per-act helper does not error — the act silently runs as whichever tenant acted last, producing self-consistent data under the wrong tenant that no invariant can flag; the journey quietly tests less than it claims. The hook cannot be forgotten per step, and the runner rejects an override that returns without invoking the execution closure — the other silent failure shape.

## Trails and failure output

Steps are recorded with an instance label (`A: cast vote`, `B: draft post`); single-instance trails stay unlabelled. The failure message names each label's journey class, and the seed replays the merged trail exactly — same interleaving, same data choices.

## Modes

- **Canonical** (shuffles' baseline, trail 0): instance A's declared order in full, then B's. This is itself a meaningful test — it proves each journey survives running against a database that already contains the other's data — and cross-instance invariants run throughout.
- **Shuffled / repeat-heavy**: at every tick the picker chooses among all enabled steps of all instances, weighted as usual.
- **Exhaustive** is refused for interleaved runs: the ordering space is the product of the instances' orderings and defeats the purpose of a bounded mode.

## What this catches that nothing else can

The fixture's planted bug: refreshing a community's cached `posts_count` with a query that forgot its community scope. With a single instance there is only one community, so the scoped and unscoped counts are *provably identical* — no single-instance trail, however shuffled, can ever detect it. The moment a second community's journey interleaves, the unscoped count bleeds across tenants and the standard `cachedColumnMatches` invariant catches it. The package's test suite asserts both halves of that claim.
