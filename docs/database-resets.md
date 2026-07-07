# Resetting state

Each trail runs against fresh state. The default wraps every trail in a transaction on the default connection and rolls it back — fast, and correct for the common case. When the code under test commits or manages transactions itself, or writes across several stores, opt into a different reset on the runner.

## Truncation

When the code under test commits (so a wrapping transaction can't undo it), reset by truncating the tables it touches:

```php
$this->journey(PostLifecycleJourney::class)
    ->resetByTruncating('votes', 'posts', 'communities', 'users')
    ->run();
```

## Multiple connections and external stores

When a journey writes across more than one store, `resetConnections()` rolls back a transaction on each named connection, and `resetExternal()` runs a cleanup for anything a transaction can't undo (a Mongo or Elasticsearch wipe, a cache flush). The two compose — and `resetExternal()` on its own still transacts the default connection:

```php
$this->journey(AnalyticsJourney::class)
    ->resetConnections('mysql', 'analytics')             // roll back both SQL connections
    ->resetExternal(fn () => $this->wipeMongoContacts())  // then clean the document store
    ->run();
```

## A bespoke wrapper

For anything more involved, supply the whole wrapper yourself. It receives the trail as a closure and must invoke it:

```php
$this->journey(PostLifecycleJourney::class)
    ->resetWith(function (Closure $trail): void {
        // begin, run, restore — whatever your app needs
        $trail();
    })
    ->run();
```

To change the default for **every** journey a test case runs, override `wrapTrail()` on the test case rather than repeating `resetWith()` on each run.

## Seed inside a step, not `setUp()`

Whatever the reset, a store the trail seeds must be seeded *inside a step*, not once in `setUp()`: the reset runs after every trail, so state established before the run is gone after the first one. The usual shape is a first step that creates the state (and re-creates it when absent), as the `create community` step does in the [quick start](quick-start.md).

The one exception is [`actors()`](actors-http.md) users, which must exist before the run — create those in the test and pass them in; anything created *inside* a trail is rolled back.
