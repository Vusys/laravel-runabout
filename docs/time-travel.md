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

## Why make time a step

Any logic bucketed by day — counters, digests, rate limits, "votes today" — behaves differently depending on whether events land inside one bucket or straddle a boundary. Modelling the clock move as an ordinary step means the shuffler exercises both: some trails cross the day boundary mid-journey, others stay within it, and a bug that only appears when the bucket rolls over surfaces without you hand-writing that ordering.

Because `travel` is just an `act`, it obeys the same constraints as any other step — gate it with `after()` or `when()` if it should only fire once prerequisites exist, and mark it `repeatable()` if the journey should be able to advance the clock more than once.
