# Troubleshooting

Runabout tries to fail with a message that names the fix. This page collects the ones you're most likely to meet, what actually causes each, and what to change. Failures of your *application* — a violated invariant, a failed assertion — are not errors in this sense; those are the tool working, and [Reproducing failures](reproducing-failures.md) covers what to do with them.

## Deadlock: no step is enabled

```
Deadlock: no step is enabled but these steps have not run: "publish post", "lock post".
A when()/after() constraint is unsatisfiable from here.
```

A shuffled trail reached a state where nothing is eligible but some steps still owe a run. This is a bug in the journey's constraints, not in your app.

The usual causes:

- **A `when()` that can latch permanently false.** A step gated on `fn ($ctx) => ! $post->locked` becomes unreachable the moment some other step locks the post. If the shuffler picks that step first, the gated step can never run.
- **A mutually exclusive pair.** Two steps that each disable the other leave whichever loses the coin flip stranded.
- **A `repeatable(min: n)` step that stops being eligible.** The deadlock check counts a step as pending until it has run `minRuns()` times, so a step needing three runs whose precondition goes false after one deadlocks just as surely as one that never ran.

Fix it by loosening the precondition, adding an `after()` so the step is only *considered* once the world supports it, or making the step's action tolerate the state it was trying to exclude. Bounding the trail further won't help — the trail is stuck, not long.

## Runaway trail

```
Runaway trail: 250 steps executed without completing the journey.
Bound your repeatable() steps or loosen their preconditions.
```

The opposite failure: the picker kept finding eligible steps and the journey never finished. The cap is `25 ×` the total minimum runs across every step, with a floor of 100 executions, so a small journey gets at least 100 ticks before this fires.

This nearly always means an unbounded `repeatable()` step whose companion "closing" step can't become eligible — the trail can always pick the repeatable one, so it does, forever. Give the repeatable step a `max`, or check why the step that ends the journey never satisfies its precondition.

## Step not enabled in declared order

```
Step "publish post" is not enabled when reached in declared order (round robin across
instances); the canonical order must be a valid trail. Check its when()/after() constraints.
```

The canonical trail — the declared order, run before any shuffle — must itself be a legal ordering. Two things commonly break it:

- **An `after()` naming a step declared later.** Declaration order *is* the canonical order, so a step can only depend on steps above it.
- **A `when()` that isn't true yet at that position.** A precondition with no `after()` is evaluated before anything has run, against a world that doesn't exist yet.

In [interleaved](interleaving.md) runs the canonical order is round robin across instances by declared position (A's first step, B's first, A's second, …), so a step gated on another instance's state must tolerate that instance having only *started*, not finished.

## Journey definition errors

These are all `InvalidJourneyException` and all thrown before any step runs:

| Message | Cause |
|---|---|
| `Journey X defines no steps.` | `steps()` returned an empty array. |
| `Journey X has duplicate step names.` | Two steps share a name. Names key the run history and the replay artifact, so they must be unique. |
| `Step "a" declares after("b") but no step has that name.` | A typo in `after()`, or the dependency was renamed and this call wasn't. |
| `At least one journey is required.` | `interleave()` was called with no arguments. |

Step builder arguments are validated where they're set, so these throw as the journey is constructed:

| Message | Cause |
|---|---|
| `Step "x" needs a minimum of at least 1 run, got 0.` | `repeatable(min: 0)`. Use the default `min: 1`; a step that may run zero times is expressed with `when()`, not `min`. |
| `Step "x" has min 5 greater than max 3.` | `repeatable(max: 3, min: 5)` can never be satisfied. |
| `Step "x" needs a weight of at least 1, got 0.` | `weight()` is a relative multiplier, so it must be positive. To make a step rarer, raise the others. |

One quiet non-error worth knowing: `repeatable(max: 1)` does **not** make a step repeatable. A step counts as repeatable only when its maximum exceeds one, so `max: 1` leaves it a once-only step and `repeatHeavy()` will not favour it.

## `aroundStep()` returned without invoking the execution

```
App\Journeys\TenantJourney::aroundStep() returned without invoking the execution closure it was given.
```

An [`aroundStep()`](interleaving.md#per-instance-environment-aroundstep) override must call the closure it receives. A wrapper that returns early — an unmet guard clause, an accidental `return` — would silently skip the step and its invariant checks, so the run rejects it instead of passing a test that didn't test anything.

If you need a step to *sometimes* not run, that's a `when()` precondition on the step, not a conditional wrapper.

## Exhaustive mode refuses to run

```
Exhaustive mode would run more than 720 orderings for the 7 steps of App\Journeys\BigJourney.
Shrink the journey or raise the limit: exhaustive(limit: ...).
```

Orderings grow factorially: six steps is exactly 720, seven is 5,040. The limit is a guard against accidentally asking for a run that never finishes. Either raise it deliberately (`exhaustive(limit: 5040)`) or accept that sampling is the right tool at this size and use `shuffles()`.

```
Exhaustive mode is not available for interleaved journeys:
the ordering space is the product of the instances' orderings.
```

[Interleaved](interleaving.md) runs multiply each instance's ordering space together, so exhaustiveness stops being meaningful well before it stops being affordable. Use `shuffles()` there.

## A replay artifact stopped working

```
The replay trail is not viable: publish post is no longer reachable in this order
(an after()/when() dependency it relied on may have been removed).
```

A `RUNABOUT_TRAIL` artifact names steps and the order they ran in. If the journey has changed since the artifact was captured — a step renamed or deleted, a constraint tightened — the recorded order may no longer be legal. The artifact is stale, not the test.

The same failure reports `(unknown step)` when a token names a step that no longer exists, and `(unknown instance)` when an interleaved artifact is replayed against a different number of journey instances.

Re-run the journey normally to find the bug again and capture a fresh artifact. If you're mid-fix and the bug is already gone, that's the artifact doing its job.

## Context and actor errors

| Message | Cause |
|---|---|
| `No actor named "manager" is registered. Known actors: agent, admin.` | A typo, or the actor was registered on a different trail. Actors live on the per-trail context — register them in a step or declare them with [`actors()`](actors-http.md#declaring-fixed-participants-with-actors), not in `setUp()`. |
| `No HTTP driver is bound to this context.` | The journey was run outside `RunsJourneys::journey()` — a runner constructed by hand has no test case to make requests through. |
| `Context key "post" holds null, expected App\Models\Post.` | `instance()` found the wrong type, usually because the step that remembers the key hasn't run yet in this ordering. Gate the reader with `after()`. |
| `Context key "post id" holds string, expected int.` | An id read straight out of a JSON response body arrives as a string on some drivers — cast at the `remember()` call. |
| `No actor has made a request yet in this trail.` | `lastResponse()` was called before any actor request, typically from an assertion on a step whose `act` doesn't make one. |

## An invariant complains about the initial state

```
Post 1 appeared in state "published", which is not a legal initial state (draft).
If the row exists before the journey, observe it in a leading step so its initial
state is recorded before any step transitions it.
```

`legalTransitions` builds each row's history from what it observes, and invariants are checked *after* every step — not before the first one. For a row created inside the journey that's fine. For a row that existed beforehand, the invariant's first look comes after the opening step has already moved it, so that move is mistaken for the row's starting state.

Add [`->fromStart()`](invariants.md#baseline-observation-fromstart) so the invariant also takes a reading before any step runs.

## My seed or trail is being ignored

Only one mode runs, and they take precedence in this order:

1. `exhaustive()`
2. `trail()`, then `RUNABOUT_TRAIL`
3. `seed()`, then `RUNABOUT_SEED`
4. `shuffles()` — the default

So `RUNABOUT_SEED` has no effect on a journey whose test already calls `exhaustive()` or `trail()`, and an in-code `seed()` beats the environment variable rather than the other way round. See [Environment variables](environment.md#precedence) for the full table.

Also worth knowing: a seeded replay runs **one** trail, not the canonical order plus shuffles. If you're replaying and seeing a single trail, that's correct.

## State leaks between trails

Every trail is reset — by default a transaction rolled back on the default connection. Anything created before the run is therefore gone after the first trail completes.

If trail 1 passes and trail 2 fails on missing data, the setup almost certainly lives in `setUp()` or a `beforeEach`. Move it into a first step so it's re-created per trail. The one deliberate exception is [`actors()`](actors-http.md) users, which must exist *before* the run — see [Resetting state](database-resets.md#seed-inside-a-step-not-setup).

The mirror image — trail 2 failing because trail 1's rows are *still there* — means the code under test commits its own transactions, so the wrapping rollback can't undo them. Switch to `resetByTruncating()`.

## The failure wasn't shrunk

Shrinking is skipped, by design, when:

- the failing trail was **canonical**, **exhaustive**, or already **replayed** — only `shuffled` and `repeat-heavy` trails are shrunk, because the others already reproduce directly;
- the failure is **structural** (a deadlock, a runaway, a broken definition) — there's no meaningful shorter subsequence of a journey that was never valid;
- the trail is a **single execution** — there's nothing to remove;
- `RUNABOUT_SHRINK=0` is set;
- no shorter trail and no smaller values reproduced the *same* failure, in which case the original is reported unchanged.

If shrinking runs but the drawn values don't minimise, check whether the step reaches for `$ctx->randomizer()`. The raw handle marks that execution value-opaque: its draws still replay exactly, but the shrinker can't see through the handle to minimise them. Prefer `pick()` and `randomInt()` where you want small, readable values in the reported trail.

## Every trail explores the same ordering

Set `RUNABOUT_COVERAGE=1` and read the distinct-ordering count. If fifty trails produce one or two orderings, the journey is fully constrained — an `after()` chain that pins every step into a single legal sequence. That's a valid journey, but the shuffling is buying nothing, and the run is an ordinary feature test wearing a costume.

Loosen the chain to whatever the domain actually requires. `after()` should encode genuine prerequisites ("can't publish before drafting"), not the order you happened to write the steps in. See [Trails & coverage](observability.md).
