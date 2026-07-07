# Reproducing failures

When a trail fails, Runabout's job is to hand you the smallest, most direct reproduction it can — and to make re-running it a single environment variable.

## What a failure looks like

```
Journey Tests\Journeys\PostLifecycleJourney failed (shuffled, seed 923206350) at step 6 ("cast vote").
Trail:
   1. create community
   2. draft post
   3. publish post
   4. cast vote
   5. cast vote (run 2)
>  6. cast vote (run 3)
Invariant "Post.score matches its source data" violated after step "cast vote": Post 1 has a stale cached score: the column holds 1 but the source data gives 2.
Replay with RUNABOUT_SEED=923206350.
```

The output names the mode and seed, prints the full trail with the failing step marked, states which invariant (or assertion) failed and why, and gives you the exact command to replay it.

## Replaying by seed

```bash
RUNABOUT_SEED=923206350 vendor/bin/phpunit --filter test_post_lifecycle
```

re-runs that exact trail — same order, same voters, same values — until you've fixed the bug. In code, `->seed(923206350)` does the same thing. A bare seed reproduces any *unshrunk* shuffled trail.

## Automatic shrinking

A seed reproduces a failing trail, but a repeat-heavy trail that fails on execution 23 hands you 23 steps to read when perhaps four matter. So when a shuffled or repeat-heavy trail fails, Runabout automatically minimises it — removing executions that don't change the outcome — and leads the failure output with the shortest trail that still fails *the same way*:

```
Shrunk from 13 executions to 3 (49 replays):
Journey Tests\Journeys\MaxDealCacheJourney failed (replayed, seed 1351231025) at step 3 ("close largest opportunity").
Trail:
   1. open opportunity [drew 1, 50]
   2. open opportunity (run 2) [drew 1, 51]
>  3. close largest opportunity
Invariant "Account.largest_open_deal matches its source data" violated after step "close largest opportunity": Account 1 has a stale cached largest_open_deal: the column holds 51 but the source data gives 50.
Replay with RUNABOUT_TRAIL='{"seed":1351231025,"steps":[[null,"open opportunity",3,[1,50]],[null,"open opportunity",4,[1,51]],[null,"close largest opportunity",1]]}'.
Replay the full 13-execution trail with RUNABOUT_SEED=1351231025.
```

"The same way" is exact: same exception class plus the invariant's (or failing step's) name, so shrinking never quietly swaps the reported bug for a different one it happened to trip while removing steps. The search is deterministic and budget-capped — `RUNABOUT_SHRINK=0` turns it off, `RUNABOUT_SHRINK_BUDGET` changes the cap (default 200 replays).

Shrinking runs in two passes:

1. **Length** — remove executions that don't change the outcome.
2. **Values** — push each surviving `randomInt`/`pick` toward the low end of its domain, stopping at the boundary that still reproduces.

That is why the deal amounts above land at `50` and `51` (one lower and they'd tie, and the bug would vanish) and the noise name-suffix draws collapse to `1` — the trail reads like a hand-written test (`open opportunity [drew 1, 50]`) instead of a seed to go re-derive.

## Replaying a shrunk trail: `RUNABOUT_TRAIL`

`RUNABOUT_TRAIL` replays that exact shrunk trail — order and data both — which a bare seed cannot, because a shrunk or repeat-heavy trail is no longer what the seed alone produces:

```bash
RUNABOUT_TRAIL='{"seed":1351231025,"steps":[...]}' vendor/bin/phpunit --filter test_max_deal_cache
```

For a long artifact, read it from a file with `RUNABOUT_TRAIL=@path.json`. In code, `->trail($artifact)` accepts the same JSON, a `@path.json` reference, or the decoded array.

This works because each execution's random draws depend only on which execution it is, not on what ran before it (value shrinking pins the minimised draws in the artifact's optional fourth element). That position-independence is also what lets shrinking be sound: removing a step never shifts the survivors' draws.

See [Environment variables](environment.md) for the full list of `RUNABOUT_*` knobs.
