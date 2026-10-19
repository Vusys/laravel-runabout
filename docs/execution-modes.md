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

## `minLength(int $executions)`

Keeps each shuffled trail going until it holds at least `$executions` step executions, not just until every step's minimum is met. A trail with no eligible step left ends early instead of failing. Use it with journeys made mostly of [`optional()`](steps.md) steps: without it, a shuffled trail stops as soon as its few required steps have run, and the shuffles collapse into a handful of short, repeated orderings. The canonical trail is unaffected. The default, 0, leaves trails, and therefore existing seeds, unchanged.

## `alsoTrails(array ...$orderings)`

Some bugs need a specific ordering of four or five steps, which shuffles reach only occasionally: a journey can stay green at 30 or 200 shuffles and fail at 500. Once you know an ordering is risky (a hunch, or the ordering a fixed bug needed), keep it in every run:

```php
$this->journey(PostLifecycleJourney::class)
    ->alsoTrails(
        ['draft post', 'publish post', 'lock post', 'cast vote'],
        ['draft post', 'publish post', 'cast vote', 'cast vote'],
    )
    ->run();
```

- Each ordering is a list of step names, run in that order after the canonical trail and before the shuffles. A repeated name is that step's next run.
- Draws come from the canonical trail's seed, so a step's values match its canonical run.
- A failure prints the `RUNABOUT_TRAIL` artifact that replays it.
- An ordering that names an unknown step, or that a step's `after()`/`when()` constraints rule out, fails the run, so the list can't go stale without anyone noticing.
- In an [interleaved](interleaving.md) run, prefix each name with its instance label: `'B: draft post'`.

It doesn't change the shuffles, so every seed still replays the same trail.

## `exhaustive(int $limit = 720)`

Runs **every** valid ordering rather than sampling, and throws if the journey has more than `$limit` orderings (default 720). Suits small journeys where you'd rather prove every ordering than hope the seeds covered them. Not available for [interleaved](interleaving.md) journeys.

## `seed(int $seed)`

Replays one exact shuffled trail by its seed — the line every failure prints for you. Equivalent to setting the `RUNABOUT_SEED` [environment variable](environment.md). For a shrunk or repeat-heavy trail that a bare seed can't reproduce, replay the full artifact with `trail()` / `RUNABOUT_TRAIL` instead — see [Reproducing failures](reproducing-failures.md).

By default seeds are derived deterministically from the journey class and trail index, so ordinary CI runs are stable from commit to commit. Set `RUNABOUT_RANDOMIZE=1` for a nightly job that hunts orderings the fixed seeds never visit.
