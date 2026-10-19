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

Below that, PHPUnit prints the exception's causes: the `InvariantViolationException` naming the invariant and step, then your assertion with its comparison diff. Runabout's own frames are left out of those stack traces, the way PHPUnit already leaves out vendor code, so each trace points at lines in your journey and test. The first frame under the assertion is the line that failed.

### When a step's request was rejected

If the failing step made an actor request, the message also describes the last response that step received, because a failed step assertion in an HTTP journey usually means the app rejected the request for a reason you didn't expect:

```
Expected response status code [201] but received 422.
Last response (POST /posts): 422
  body: {"message":"The title field is required.","errors":{"title":["The title field is required."]}}
```

It shows the request's method and URI, the status, the target of a redirect, and either the validation errors flashed to the session or the start of the body (300 characters). An invariant failure doesn't show it, and neither does a step that made no request of its own.

## Replaying by seed

```bash
RUNABOUT_SEED=923206350 vendor/bin/phpunit --filter test_post_lifecycle
```

re-runs that exact trail — same order, same voters, same values — until you've fixed the bug. In code, `->seed(923206350)` does the same thing. A bare seed reproduces any *unshrunk* shuffled trail.

## Automatic shrinking

A seed reproduces a failing trail, but a repeat-heavy trail that fails on execution 23 hands you 23 steps to read when perhaps four matter. So when a canonical, shuffled or repeat-heavy trail fails, Runabout automatically minimises it — removing executions that don't change the outcome — and leads the failure output with the shortest trail that still fails *the same way*:

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

When every execution turns out to be needed but drawn values could still be minimised, the headline says so instead: `Shrunk drawn values; all 2 executions are needed (12 replays):`.

A failure in the canonical (declared) order is shrunk the same way, because the declared order is often the first trail to fail and runs every step. Its headline reads `Shrunk the canonical order from 7 executions to 3 (…):`, and the last line says `Re-run the journey to reproduce the full 7-execution trail.` instead of giving a seed, because a seed replays a shuffled trail rather than the canonical one. The shrunk trail replays with `RUNABOUT_TRAIL` like any other.

Each candidate is a full replay of the journey, so on slow HTTP journeys shrinking costs real time (a 10-step trail can take 30-odd replays). Set `RUNABOUT_SHRINK=0` when you only want the raw failure.

"The same way" is exact: same exception class plus the invariant's (or failing step's) name, so shrinking never quietly swaps the reported bug for a different one it happened to trip while removing steps. The search is deterministic and budget-capped — `RUNABOUT_SHRINK=0` turns it off, `RUNABOUT_SHRINK_BUDGET` changes the cap (default 200 replays).

Shrinking runs in two passes:

1. **Length** — remove executions that don't change the outcome.
2. **Values** — push each surviving `randomInt`/`pick` toward the low end of its domain, stopping at the boundary that still reproduces.

That is why the deal amounts above land at `50` and `51` (one lower and they'd tie, and the bug would vanish) and the noise name-suffix draws collapse to `1` — the trail reads like a hand-written test (`open opportunity [drew 1, 50]`) instead of a seed to go re-derive.

A `pick()` draw also names what it picked, so a step that chooses which record to act on says which one:

```
   4. invoice [drew 1 → Order #140, 0 → LineItem #212, 1]
```

Eloquent models show their class basename and key, enums show `PostStatus::Draft`, and scalars show their value (strings quoted, cut to 24 characters). Any other option shows only its index, as does every `randomInt()` draw. Model keys come from the database, so on engines whose auto-increment survives the per-trail rollback (MySQL) the key in a replay can differ from the one in the original report. The index is what replays; the name is only there to read.

### When the failure depends on earlier trails

Replays run in the same PHP process as the trails before them. A database rollback resets rows, but not in-memory state: cache entries and rate-limiter counters in the array cache store, Laravel Context, auth guards, or the user objects actors hold. If that state is what made a trail fail, the minimal trail also fails during shrinking, but only because of what ran earlier, and its replay line may not reproduce it on its own.

So when a trail other than the first fails, Runabout replays the minimal trail again after clearing each kind of state in turn. If it then passes, the failure ends with a note naming what had to be cleared and how to stop it leaking:

```
The minimal trail passed when replayed after flushing the array cache store, so the failure depends on state that earlier trails or replays left behind, and the replay line above may not reproduce it on its own. Cached values or rate-limiter counters are leaking between trails; call isolateTrails() to flush them after each trail.
```

Only in-memory (array) cache stores are flushed for this, never a real one. To spot leaks before they cause a failure, set [`RUNABOUT_LEAK_CHECK=1`](environment.md).

## Replaying a shrunk trail: `RUNABOUT_TRAIL`

`RUNABOUT_TRAIL` replays that exact shrunk trail — order and data both — which a bare seed cannot, because a shrunk or repeat-heavy trail is no longer what the seed alone produces:

```bash
RUNABOUT_TRAIL='{"seed":1351231025,"steps":[...]}' vendor/bin/phpunit --filter test_max_deal_cache
```

For a long artifact, read it from a file with `RUNABOUT_TRAIL=@path.json`. In code, `->trail($artifact)` accepts the same JSON, a `@path.json` reference, or the decoded array.

This works because each execution's random draws depend only on which execution it is, not on what ran before it (value shrinking pins the minimised draws in the artifact's optional fourth element). That position-independence is also what lets shrinking be sound: removing a step never shifts the survivors' draws.

## Known issues: `knownIssue()`

A journey that keeps failing on a bug you've already filed stops looking for new ones: every run ends at the same failure. `knownIssue()` tolerates it while it's being fixed, so exploration carries on past it:

```php
$this->journey(new OrderJourney)
    ->shuffles(200)
    ->knownIssue('orders.total matches its lines', reason: 'ticket 812')
    ->knownIssue(step: 'refund order', throwing: QueryException::class)
    ->run();
```

Name an invariant (its name, or the `Invariant` itself), or a step, the exception class it throws (subclasses included), or both. Failure messages aren't matched, because their wording isn't covered by the stability promise. Pass `name:` to a built-in invariant to give it a short, stable name to refer to.

- The first time a known issue fires, its trail is shrunk and recorded as usual. A known invariant is then no longer checked for the rest of the run. A known step failure ends each trail it fires in, and the run moves on to the next trail.
- A run where only known issues fired is **skipped**. The skip message lists each one, how many trails it fired in, and its first shrunk failure with the `RUNABOUT_TRAIL` that replays it.
- A new failure fails the run as normal, with the known issues that also fired listed after it.
- A known issue that never fires prints a warning on stderr, asking whether it's been fixed. `->strictKnownIssues()` fails the run instead. The default is a warning because a short run can miss a bug that is still there.

Replaying with `seed()`, `trail()`, `RUNABOUT_SEED` or `RUNABOUT_TRAIL` ignores known issues, so the failure reproduces while you debug it.

See [Environment variables](environment.md) for the full list of `RUNABOUT_*` settings.
