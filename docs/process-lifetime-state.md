# Process-lifetime state

Runabout resets the database between trails, but a PHPUnit process holds far more state than that, and much of it lives until the test ends. Journeys exercise it much harder than ordinary tests do, because one test makes hundreds of requests over many trails. In production each request starts in a fresh process, or at least a freshly reset application. In a test, a request inherits whatever earlier requests and trails left in memory.

The first sign is usually a confusing failure: a trail that fails only after others have run, or a [shrunk trail that passes on replay](troubleshooting.md#my-shrunk-trail-passes-when-i-replay-it).

## What survives, and the fix

| State | Survives | Typical symptom | Fix |
|---|---|---|---|
| Rate-limiter counters in an in-memory cache store | trails | actors are answered with 429s after a few trails | flush the store after each trail with [`resetExternal()`](database-resets.md#multiple-connections-and-external-stores) |
| Cache entries keyed by ids | trails | trail 2 reads trail 1's cached rows, especially on SQLite, which hands the same ids out again after a rollback | `resetExternal()`, as above |
| Static memo caches and singletons in app code | trails | stale lookups after a rollback | reset them in `resetExternal()` |
| Laravel `Context` and scoped container instances | requests | a flag one request sets changes the behaviour of later ones | [`isolateRequests()`](actors-http.md#isolating-each-request) |
| The actor's user instance | trails | permissions another actor granted or revoked are ignored | [`freshActors()`](actors-http.md#reloading-users-before-each-request) |
| Mail, notification, queue and event fakes | the test | counts and assertions accumulate across trails | fake inside a step, not in `setUp()` |
| Auth guards logged in from `setUp()` | the test | the default guard drifts, or the wrong user is logged in | authenticate through [actors](actors-http.md) |
| The clock moved with `$ctx->travel()` or `travelTo()` | nothing | none: Runabout unwinds it at the end of every trail | [Time travel](time-travel.md) |

`resetExternal()` runs a cleanup after every trail, so it can flush an in-memory cache store and reset static caches:

```php
$this->journey(SomeJourney::class)
    ->resetExternal(function (): void {
        Cache::store('array')->flush();
        Settings::flushMemo();
    })
    ->run();
```

`isolateRequests()` clears `Context` and scoped instances before every actor request, and reloads actors' users. It's off by default, so outcomes and trails don't change unless you ask.

## Fakes

`Mail::fake()`, `Notification::fake()`, `Queue::fake()` and `Event::fake()` in `setUp()` install one fake for the whole test, and it records every trail's messages. `assertSentCount(1)` is then wrong from the second trail, and `assertNothingSent()` fails as soon as any trail sent anything.

Install the fake in a step that runs first, so each trail starts with a fresh one, or make the assertions trail-relative:

```php
Step::make('set up')->act(function (Context $ctx): void {
    Mail::fake();
}),
```
