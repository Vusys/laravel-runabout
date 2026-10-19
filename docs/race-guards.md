# Testing race guards

Runabout runs one step at a time in one process, so it can't produce real concurrency, and neither can PHPUnit. Check-then-act races are still ordering bugs, and they are the kind journeys exist to find:

- an existence check followed by an insert ("one open request per user");
- a stock check followed by an allocation;
- an availability check followed by an assignment.

Apps guard these with a row lock and a re-check, but nothing tests that the guard is there, or that it works. `$ctx->racing()` does, by injecting the interference deterministically.

## `racing()`

```php
Step::make('request item')
    ->act(fn (Context $ctx) => $ctx->racing(
        table: 'item_requests',
        competitor: fn () => RequestItem::run($ctx->instance('item', Item::class), $ctx->as('member')->user()),
        request: fn () => $ctx->as('member')->postJson("/items/{$ctx->integer('item id')}/request"),
    ));
```

While `request` runs, Runabout watches the queries it makes. At an unlocked read of `table`, after the read returns and before the app can act on it, the `competitor` runs: the effect of a parallel request landing in the check-then-act window. Then the request carries on with what it read, now out of date. `racing()` returns whatever `request` returns.

Your [invariants](invariants.md) judge the result (no duplicates, no over-allocation), so no new assertions are needed. `Invariants::uniqueBy()` is the usual partner for an existence check.

- **Locked reads aren't interfered with.** A `SELECT` with `FOR UPDATE`, `FOR SHARE` or `LOCK IN SHARE MODE` (Laravel's `lockForUpdate()` and `sharedLock()`) would hold a real competitor back, so injecting there would be a false positive. A correctly guarded path passes. Once the request has locked a row of the table inside a transaction it opened, later reads aren't interfered with either, until that transaction ends: a real competitor would be waiting on the lock. A lock taken outside such a transaction is released at once, as in production, so it doesn't count.
- **Which read is seeded.** At each unlocked read of the table the step draws whether to interfere there, usually at the first. The choice replays from the seed or `RUNABOUT_TRAIL`, and shrinking moves it to the earliest read that still fails.
- **At most once per request.** The competitor runs once at most, and its own queries are never interfered with.
- **The competitor should call the app's own code**, such as the action or service the endpoint uses, so it writes the logs, counters and events a real request would.

Only steps that call `racing()` draw anything extra, so other steps' seeds and artifacts replay unchanged.

## Drivers

Interference needs a driver whose SQL carries lock clauses, so a locked read can be told from an unlocked one: MySQL, MariaDB or Postgres. Laravel's SQLite grammar drops them. On SQLite, `racing()` runs the request without interference, the rest of the journey runs as normal, and the run prints a note on stderr once. Run race journeys against the database you deploy on.

## Limits

- One competitor, at one interleaving point, per `racing()` call.
- Competitors are written by hand.
- Unlocked reads are found from the SQL text: a `SELECT` naming the table after `FROM` or `JOIN`, without a lock clause. A read through a view, or a schema-qualified name, isn't matched.
- The competitor runs on the same connection and in the same transaction as the request, so it sees the request's uncommitted writes. A real parallel request would not.
