# Shrinking — design

Status: **implemented.** Seed schema v2, explicit-order replay (`RUNABOUT_TRAIL`), and the budgeted ddmin shrink loop all shipped; shrinking runs automatically on a failing shuffled/repeat-heavy trail (`RUNABOUT_SHRINK=0` disables). The long failing trails it exists for come from the [shrinker benchmark corpus](shrinker-benchmarks.md), a toy CRM of planted bugs with known-minimal counterexamples. The design below is what was built; one refinement learned in the process is recorded at the end.

A seed replays a failing trail exactly, and that solves reproduction. It does not solve comprehension: a repeat-heavy trail that fails on execution 23 hands the developer 23 steps of reading, of which perhaps four matter. Shrinking is the missing half — automatically minimising a failing trail to the shortest subsequence that still produces the same failure, so the failure output reads like the bug report a colleague would have written by hand: "create, rename, create, delete — counter is now wrong".

## Why the naive approach cannot work

The obvious shrinker — drop an execution from the trail, replay from the same seed, see if it still fails — is unsound in the current engine, and understanding why dictates the whole design. One `Mt19937` stream, seeded once per trail, drives everything in sequence: the picker's weighted roll for each tick and every data draw (`randomInt()`, `pick()`, `randomizer()`) inside every step. Remove one execution and every subsequent draw shifts: the surviving steps pick different names, different quantities, different branches. The candidate is not "the same trail minus one step" — it is an unrelated trail, and whether it fails says nothing about whether the removed step mattered.

So a shrinker needs surviving executions to reproduce their draws *independently of their position in the trail*. There are two ways to get that property.

## Design A, considered and rejected: record and replay draws

The property-based-testing lineage (Hypothesis most explicitly) records the sequence of values drawn during the failing run and replays candidates from the recording instead of the seed. Runabout could do the same: tag each recorded execution with the draws it consumed, and have candidate replays serve recorded values back in order, validating that each request (method and bounds) matches what was recorded.

It works, but it buys the property at a steep price here:

- **Machinery.** A `Recording` value object, per-execution draw partitions, a request-signature-matching policy, and a divergence policy for when a surviving step asks a different question than it asked originally (because removals changed observable state).
- **The escape hatch breaks.** `$ctx->randomizer()` hands out the raw `Randomizer`, which is `final` — it cannot be wrapped or subclassed, so draws made through it are invisible to the recorder and unreplayable. Any journey touching it would be silently unshrinkable.
- **The replay artifact is a value dump.** Reproducing a shrunk trail outside the shrinking run means serialising recorded draw values — opaque, unreadable, and tied to the recording format.

## Design B, chosen: position-independent randomness (seed schema v2)

Make the draws position-independent at the source instead of recording them after the fact. The trail seed stops feeding one shared stream and instead derives a family of streams:

- **One picker stream** for the trail: the shuffled/repeat-heavy picker rolls against a stream derived from the trail seed alone. Order decisions live here and nowhere else.
- **One data stream per execution**, derived from the trail seed plus the execution's identity: instance label, step name, and run index — something like `Mt19937(hash(seed, 'A', 'create a tag', 2))`. The runner installs the execution's stream in the context for the duration of that execution; `randomInt()`, `pick()`, *and* `randomizer()` all serve from it.

Everything is still fully determined by one integer — a seed replays the merged trail exactly, as today. But a step execution's data now depends only on *which execution it is*, never on what ran before it. That single change makes shrinking almost trivial and pays for itself three more times:

- **A candidate is just an order list.** Replaying a subsequence needs the trail seed plus the ordered execution tokens — no recorded values, no signature matching. Surviving executions reproduce their draws verbatim by construction. The engine already knows how to run an explicit order (`runOrder()`, built for exhaustive mode); replay generalises it to interleaved, repeat-carrying orders.
- **`randomizer()` stays safe.** It returns the current execution's stream, so even raw-randomizer journeys shrink correctly. Design A's silent hole becomes a non-issue.
- **Data is stable across orderings.** The nth run of a step draws the same values in the canonical trail, every shuffle, and every shrunk replay of the same trail seed. "the bug needs the title `post-417` specifically" survives reordering; two interleaved instances stop being data-entangled through the shared stream; and diffing a failing order against a passing one compares like with like.
- **The replay artifact is human-readable.** A labelled token list, not a byte blob (format below).

### The cost, and why the window is now

Seed schema v2 changes what every existing seed produces: data draws leave the shared stream (so the picker's rolls shift), and data values re-key entirely. Every pinned `RUNABOUT_SEED` in the world breaks — which is precisely why this must land while "the world" is one machine. Nothing has ever been pushed; there are no external users and no pinned seeds outside this repo. The package's own suite is the only migration cost: fixture tests asserting that N shuffles catch a planted bug are calibrated against current seeds and may need recounting. After a release, this same change would need a seed-schema version flag and a deprecation story. Before it, it is one commit and a test-suite recalibration.

This sequencing is the doc's most important conclusion: **the v2 derivation change should not wait for the shrinker.** It lands first, alone, while it is cheap.

## Replay semantics

A replay artifact is the trail seed plus an ordered list of execution tokens, each `(instance label, step name, run index)`. Semantics that matter:

- **Run index is the stream key, not a recount.** If the original trail ran "create a tag" three times and the shrinker removes run 2, the surviving third execution keeps its token `#3` and therefore its original draws. Context run history (`timesRan()`, `ranBefore()`) still reflects what actually ran in the candidate — that is semantic state the app under test genuinely observes, and no design can (or should) hide a removal from it.
- **Enabledness is checked at every slot**, exactly as exhaustive mode does: if a token's step is not enabled when its turn comes (an `after()` dependency or `when()` precondition was removed), the candidate is not viable and counts as a failed candidate, not a failed test.
- **The every-step-at-least-once rule is relaxed.** A shrunk trail is a reproduction, not a coverage run; partial trails are the whole point.
- **Invariant checks belong to the execution unit.** They run after the acting step inside the owning instances' `aroundStep()` wrappers, as always, and any draws they make come from the acting execution's stream. Teardowns drain at trail end against a dedicated trail-end stream.

## Same-failure identity

A candidate counts as reproducing only if it fails *the same way*: same exception class, plus the invariant's labelled name for violations or the failing step's labelled name plus cause class for step failures. Message text is excluded — it carries data values that legitimately vary. A candidate that fails differently is rejected outright (no test-slippage: shrinking must never quietly swap the reported bug for a different one), though a candidate that fails the same way *earlier* in the sequence is accepted gladly — shorter is the goal. Structural failures are never shrunk: deadlocks, runaway trails, `aroundStep()` guard violations, and teardown-phase failures are engine or journey-definition errors with no meaningful "minimal subsequence".

## The shrink loop

Delta-debugging with a polish pass: try removing large chunks first (halving, ddmin-style — repeat-heavy trails are mostly removable padding), then single executions backward until a full sweep removes nothing. The shrinker itself uses no randomness, so a given failing trail always shrinks to the same result. Each candidate runs inside the trail wrapper like any other trail (transaction rollback or the journey's `resetWith()`), and probe replays do **not** notify `onTrail()` — they are internal, not part of the run the observer signed up for.

Every candidate costs a full app-level trail, so the loop is budget-capped (a default in the low hundreds of replays, tunable) and reports the best trail found when the budget runs out. Shrinking runs automatically when a trail fails — the failure has already cost a red build; spending seconds to make it legible is the right default — and `RUNABOUT_SHRINK=0` turns it off for anyone who disagrees.

## Failure output

The shrunk trail becomes the headline, with the original kept one line away:

```
Runabout journey failed: CommunityJourney + CommunityJourney
Shrunk from 23 executions to 5 (38 replays):
   1. A: draft post
   2. B: draft post
   3. A: publish post
   4. A: draft post (run 2)
>  5. B: cast vote
Invariant "A: Community.posts_count matches its source data" violated after step "B: cast vote": ...
Replay the shrunk trail:
RUNABOUT_TRAIL='{"seed":1690934040,"steps":[["A","draft post",1],["B","draft post",1],["A","publish post",1],["A","draft post",2],["B","cast vote",1]]}' vendor/bin/phpunit --filter=...
Replay the full trail:
RUNABOUT_SEED=1690934040 vendor/bin/phpunit --filter=...
```

`RUNABOUT_TRAIL` (and a matching `->trail(...)` method) replays an explicit token list under a new `'replayed'` trail mode; if an artifact ever outgrows an environment variable, `RUNABOUT_TRAIL=@path/to/file.json` reads it from disk. Explicit-order replay is independently useful beyond shrinking: it retires the open wart that `RUNABOUT_SEED` always replays plain-shuffled and cannot reproduce a repeat-heavy ordering without keeping the exact call chain — a token list carries its order with it, mode-independent.

## Out of scope, for now

- **Value shrinking** — minimising the drawn data itself (integers toward zero, picks toward the first option) after the sequence is minimal. Worth having eventually; it needs draw recording (design A's machinery) but only for the already-minimal trail, which is the cheap place to pay for it. A future hybrid, not v1.
- **Restart-on-slippage** — when a candidate fails with a *different* failure, Hypothesis restarts shrinking on the new bug. We reject the candidate instead; simpler, and the developer can rerun after fixing the first bug.
- **Cross-trail minimisation** — searching other seeds for a shorter naturally-occurring failure. The shrinker only ever works within the failing trail.

## Sequencing

1. **Seed schema v2** (picker stream + per-execution data streams): next engine change, before anything is pushed, alone in its own commit with the suite recalibrated.
2. **Explicit-order replay** (`RUNABOUT_TRAIL`, `'replayed'` mode): with or shortly after v2 — independently useful, and the shrinker's substrate.
3. **The shrink loop itself**: after flagship-journey dogfooding confirms the pain it exists to relieve, using the budgeted ddmin loop above.

## Refinement learned while building

The open worry was non-monotonic bugs — a failure that needs a step which *looks* like removable noise, so that greedy single-step removal drops it and the bug "disappears". Benchmark **S6** builds exactly this: a stale-cache bug that needs a `view` (a pure read) between two mutations. The loop handles it correctly with no special machinery, because the shrink oracle is *reproduces the same failure*, not *is shorter*: a candidate with the read removed **passes**, so it is rejected and the read is kept. ddmin is only unsound on non-monotonic failures when the oracle is approximate; with the exact same-failure oracle here, greedy removal never keeps a candidate that stopped failing. So the termination rule ("sweep until a full pass removes nothing") is sound as written — the concern does not require a "known-required" set after all.
