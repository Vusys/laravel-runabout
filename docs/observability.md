# Trails & coverage

A passing run is silent by default. When you want to see what the shuffler actually explored — while tuning the shuffle count, or asserting coverage properties — Runabout exposes each completed trail and an aggregate summary.

## Watching trails: `RUNABOUT_VERBOSE`

Set `RUNABOUT_VERBOSE=1` and every completed trail is printed to stderr as it finishes:

```
[PostLifecycleJourney] trail 3/16 (shuffled, seed 923206352)
   1. create community
   2. draft post
   3. report post
   4. publish post
   5. cast vote
   6. cast vote (run 2)
   ...
```

## Collecting trails: `onTrail()`

For anything programmatic — collecting trails, counting how often a step ran, feeding a coverage report — register a callback instead; it receives each completed `Trail` in every mode (failing trails already describe themselves in the failure output):

```php
$this->journey(PostLifecycleJourney::class)
    ->shuffles(25)
    ->onTrail(fn (Trail $trail) => $log[] = $trail->steps())
    ->run();
```

A `Trail` exposes its `seed()`, `mode()`, `isShuffled()`, `steps()`, and `artifact()` (the replayable `{seed, steps}` structure), plus `describe()` for the human-readable rendering used in failure output.

## Is the shuffle count buying coverage? `RUNABOUT_COVERAGE`

Verbose output shows individual trails; the question it can't answer is the aggregate one — across all those trails, what did the run actually explore? Set `RUNABOUT_COVERAGE=1` and a green run ends with a summary on stderr:

```
[PostLifecycleJourney] trail coverage
51 trails (1 canonical, 50 shuffled), 51 distinct orderings
Step executions:
  create community  51 runs in 51/51 trails
  draft post        51 runs in 51/51 trails
  publish post      51 runs in 51/51 trails
  a new day dawns   51 runs in 51/51 trails
  cast vote         96 runs in 51/51 trails
  report post       156 runs in 51/51 trails
  lock post         51 runs in 51/51 trails
  archive post      51 runs in 51/51 trails
  remove post       51 runs in 51/51 trails
All 54 orderings of step pairs observed (18 excluded by after()).
```

The 18 excluded pairs are the ones that `after()` makes impossible, such as "publish post" before "draft post". The count leaves them out. The summary excludes a pair if one step is after another, directly or through `after()` chains, and that other step runs at most once. If that other step is repeatable, it can run again later, so the pair stays. In an interleaved run, `after()` only constrains steps within one instance. So "B: publish post" before "A: draft post" still counts.

If the run did not observe some pairs, the summary lists them: `Orderings of step pairs never observed (3 of 54, 18 excluded by after()):`. Compare that list with your `when()` conditions. A `when()` condition can block orderings that no static check can see. An *unconstrained* pair on that list is the finding. The shuffles never tried that ordering. A bug hiding behind it is invisible at this shuffle count. Distinct orderings tell the same story from above — 51 trails producing 51 distinct orderings means the seeds aren't wasting trails re-walking the same path.

## Collecting coverage yourself: `TrailCoverage`

The aggregation is a plain object, `TrailCoverage`, so you can collect it yourself — across several runs, or to assert coverage properties in the test:

```php
$coverage = new TrailCoverage;

$this->journey(PostLifecycleJourney::class)
    ->shuffles(50)
    ->onTrail($coverage->record(...))
    ->run();

$this->assertSame(0, $coverage->timesBefore('publish post', 'draft post'));
$this->assertGreaterThan(40, $coverage->distinctOrderings());
```

Pass the journeys to the constructor, `new TrailCoverage([$journey])`, to leave out the pairs that their `after()` constraints forbid. `RUNABOUT_COVERAGE` does the same. Without the journeys, the summary lists every unobserved pair. `TrailCoverage` offers `trails()`, `distinctOrderings()`, `stepRuns()`, `timesBefore($before, $after)`, `unseenPairs()`, and `describe()` (the same summary `RUNABOUT_COVERAGE` prints).
