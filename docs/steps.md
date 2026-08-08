# Defining steps

A step is built fluently with `Step::make('name')`. Every method below returns the step, so they chain.

```php
Step::make('score criterion')
    ->act(fn (Context $ctx) => ...)          // do the thing (service call, HTTP request, job dispatch)
    ->assert(fn (Context $ctx) => ...)       // may be called multiple times; runs after the act
    ->assertWhen(                            // conditional assertion, in the spirit of Laravel's when()
        fn (Context $ctx): bool => ...,      //   truth about the observed state
        fn (Context $ctx) => ...,            //   must pass when the condition holds
        fn (Context $ctx) => ...,            //   must pass otherwise — omit it and the step claims nothing
    )
    ->after('start review')                  // sugar: only eligible once these steps have run
    ->when(fn (Context $ctx) => ...)         // raw precondition: eligible only while this is true
    ->repeatable(max: 5)                     // may run again after completing (null/omitted max = unbounded)
    ->weight(3)                              // picked 3x as often as a weight-1 step when both are eligible
    ->teardown(fn (Context $ctx) => ...);    // cleanup, registered per execution
```

## The action and its assertions

`act()` performs the step — a service call, an HTTP request, a job dispatch. `assert()` runs immediately after and may be called more than once; assertions run in the order declared.

Because a shuffled trail decides what state a step observes, assertions on repeatable steps are often conditional: voting on a published post must succeed, voting on a locked one must be rejected, and the shuffler picks which you get. `assertWhen()` keeps that as three small declarative closures instead of an `if`/`else` inside one assert body:

```php
Step::make('cast vote')
    ->act(fn (Context $ctx) => ...)
    ->assertWhen(
        fn (Context $ctx): bool => $ctx->instance('post', Post::class)->refresh()->status === 'locked',
        fn (Context $ctx) => Assert::assertTrue($ctx->get('last vote rejected')),   // when locked
        fn (Context $ctx) => Assert::assertFalse($ctx->get('last vote rejected')),  // otherwise
    );
```

Omit the third closure and the step claims nothing when the condition is false. `assert()` and `assertWhen()` mix freely on one step — though genuinely n-way logic is usually still clearer as a single `assert()` with control flow.

## Constraints: when a step is eligible

- **`after('a', 'b')`** — sugar precondition: the step is eligible only once each named step has run at least once. This is the usual way to express "you can't publish before you draft."
- **`when(fn (Context $ctx) => bool)`** — a raw precondition: eligible only while the closure returns true, evaluated fresh each tick against current state. A step with no `after()` has its `when()` evaluated before any step has run, so the closure must tolerate a world that does not exist yet; adding an `after()` prerequisite is what makes a condition safe to evaluate late, via `isEnabled()`'s short-circuit.
- **`repeatable(?int $max = null, int $min = 1)`** — the step may run again after completing. `max` null (or omitted) means unbounded; `min > 1` drives a bounded random walk that guarantees the step runs at least `min` times. Pairing a `min` above 1 with a `when()` precondition that can become permanently false is a trail that can never finish — the runaway detector catches it, but the fix is to loosen the precondition or lower `min`, not to bound the trail further.
- **`weight(int $weight)`** — relative pick weight (default 1, must be `>= 1`). A weight-3 step is picked three times as often as a weight-1 step when both are eligible.

The shuffled runner picks randomly among *currently eligible* steps until every step has run at least once. A journey whose constraints can strand it (no step eligible but some never ran) fails loudly as a **deadlock**, naming the steps that never ran; a journey that repeats forever is cut off as a **runaway**.

## Teardown

`teardown()` exists for non-database global state — frozen time, config overrides, fakes, static caches. Teardowns run at the end of the trail in reverse execution order (a repeatable step run three times registers three teardowns), they are guaranteed to run on failure, and a teardown that itself throws never masks the primary failure.

Inside an action you can register cleanup dynamically with `$ctx->defer(fn () => ...)`, which joins the same LIFO stack. Database state is handled separately — see [Resetting state](database-resets.md).
