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
  cast vote         111 runs in 51/51 trails
  report post       154 runs in 51/51 trails
  lock post         51 runs in 51/51 trails
  archive post      51 runs in 51/51 trails
  remove post       51 runs in 51/51 trails
Orderings of step pairs never observed (18 of 72):
  "draft post" before "create community"
  "publish post" before "create community"
  "publish post" before "draft post"
  ...
```

Read the never-observed list against your constraints. Every pair above is impossible by design — each names a step running before its own prerequisite — so this run has genuinely explored every ordering its constraints allow. An *unconstrained* pair on that list is the finding: the shuffles never tried that ordering, and a bug hiding behind it is invisible at this shuffle count. Distinct orderings tell the same story from above — 51 trails producing 51 distinct orderings means the seeds aren't wasting trails re-walking the same path.

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

`TrailCoverage` offers `trails()`, `distinctOrderings()`, `stepRuns()`, `timesBefore($before, $after)`, `unseenPairs()`, and `describe()` (the same summary `RUNABOUT_COVERAGE` prints).
