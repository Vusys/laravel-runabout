# Time travel

A step can move the clock, turning time itself into a shuffleable event:

```php
Step::make('a new day dawns')
    ->act(fn (Context $ctx) => $ctx->travel('+1 day'));
```

The context wraps Laravel's test clock:

- `$ctx->travel($modifier)` — move relative to now, e.g. `'+1 day'`, `'+3 hours'`.
- `$ctx->travelTo($moment)` — jump to an absolute `DateTimeInterface` or parseable string.
- `$ctx->travelBack()` — return to real time.

The clock is **unwound automatically at the end of every trail** — even a failing one — so frozen time never leaks into the next trail or test.

## Moves compound

`travel()` is relative to the clock *as it currently stands*, not to the real wall clock. Two `+1 day` moves in one trail land two days out, and a repeatable clock step advances a day per execution:

```php
Step::make('a new day dawns')
    ->repeatable(max: 3)
    ->act(fn (Context $ctx) => $ctx->travel('+1 day'));
```

That is usually what you want — it's how a trail walks across several daily buckets — but it does mean the absolute date a later step observes depends on how many times the clock step was picked before it. Assert on relative facts ("the counter reset", "the digest covers yesterday") rather than on a literal date, or use `travelTo()` to pin an absolute moment where the exact date matters.

`travelTo()` doesn't compound: it sets the clock outright, so it lands on the same moment however many times it runs.

## How the unwind works

The first `travel()` or `travelTo()` in a trail registers a single deferred reset on the trail's [teardown stack](context.md#deferred-cleanup) — one reset per trail, however many times the clock moves afterwards. That stack drains in LIFO order at the end of the trail, pass or fail, so the reset's position in the unwind is simply where the first clock move happened: teardowns registered *after* it drain first and still see the travelled clock, while teardowns registered *before* it drain after the reset and see real time.

In practice that distinction only matters for a teardown that reads timestamps. If one does, register it from a step that runs after the clock has moved, or read the value you need during the step and capture it in the closure.

`travelBack()` returns to real time immediately, mid-trail, for the occasional step that needs to compare frozen state against the real clock. It doesn't cancel the end-of-trail unwind, so it's safe to call at any point, or not at all.

## Why make time a step

Any logic bucketed by day — counters, digests, rate limits, "votes today" — behaves differently depending on whether events land inside one bucket or straddle a boundary. Modelling the clock move as an ordinary step means the shuffler exercises both: some trails cross the day boundary mid-journey, others stay within it, and a bug that only appears when the bucket rolls over surfaces without you hand-writing that ordering.

Because `travel` is just an `act`, it obeys the same constraints as any other step — gate it with `after()` or `when()` if it should only fire once prerequisites exist, and mark it `repeatable()` if the journey should be able to advance the clock more than once.
