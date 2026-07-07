# Execution modes

`journey()` returns a fluent runner. The mode decides how orderings are sampled before you call `run()`.

```php
$this->journey(PostLifecycleJourney::class)
    ->shuffles(25)          // canonical order + 25 seeded shuffles (default 10)
    ->run();

$this->journey(PostLifecycleJourney::class)
    ->repeatHeavy()         // bias the picker toward repeatable steps (default 5x)
    ->shuffles(15)
    ->run();

$this->journey(SmallJourney::class)
    ->exhaustive()          // run every valid ordering; refuses above 720 orderings
    ->run();

$this->journey(PostLifecycleJourney::class)
    ->seed(923206350)       // replay one exact trail
    ->run();
```

## `shuffles(int $count)`

The default mode. Runs the canonical (declared) order once, then `$count` seeded shuffles that respect every step's constraints. `shuffles(0)` runs only the canonical order, turning the journey into an ordinary feature test — useful for confirming a bug is invisible to the golden path. The default when you set nothing is 10.

## `repeatHeavy(int $bias = 5)`

Biases the picker toward repeatable steps (5× by default). This is the **idempotency hunter**: re-running steps is how replace-logic, counter, and quota bugs surface, and the bias finds them in far fewer trails than uniform shuffling. Combine it with `shuffles()` to set how many biased trails to run.

## `exhaustive(int $limit = 720)`

Runs **every** valid ordering rather than sampling, and throws if the journey has more than `$limit` orderings (default 720). Suits small journeys where you'd rather prove every ordering than hope the seeds covered them. Not available for [interleaved](interleaving.md) journeys.

## `seed(int $seed)`

Replays one exact shuffled trail by its seed — the line every failure prints for you. Equivalent to setting the `RUNABOUT_SEED` [environment variable](environment.md). For a shrunk or repeat-heavy trail that a bare seed can't reproduce, replay the full artifact with `trail()` / `RUNABOUT_TRAIL` instead — see [Reproducing failures](reproducing-failures.md).

By default seeds are derived deterministically from the journey class and trail index, so ordinary CI runs are stable from commit to commit. Set `RUNABOUT_RANDOMIZE=1` for a nightly job that hunts orderings the fixed seeds never visit.
