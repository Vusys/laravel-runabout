# Core concepts

Runabout has a small vocabulary. Everything else builds on these six ideas.

## Journey

A **journey** is a class you extend from `Vusys\Runabout\Journey`. It declares the `steps()` of a user journey, the `invariants()` that must always hold, and optionally the `actors()` that drive it. One journey definition is executed many times, in many orders.

```php
final class PostLifecycleJourney extends Journey
{
    public function steps(): array { /* list<Step> */ }
    public function invariants(): array { /* list<Invariant> */ }
}
```

See [Defining steps](steps.md) and [Invariants](invariants.md).

## Step

A **step** is one action in the journey, built fluently with `Step::make('name')`. It carries an action (`act`), assertions (`assert` / `assertWhen`) run right after the action, and constraints (`after`, `when`, `repeatable`, `weight`) that decide when it is eligible and how often it can run. The runner only ever picks among steps whose constraints are currently satisfied.

## Context

The **context** (`Vusys\Runabout\Context`) is the state bag threaded through a single trail. Steps `remember()` values on it and read them back with typed getters; it is also the *only* sanctioned source of randomness (`pick()`, `randomInt()`), which is what makes trails reproducible. Actors, time travel, and deferred teardown all live on the context. See [The context](context.md).

## Invariant

An **invariant** is a property that must hold after **every** step, no matter what just ran — a cached column matching its source, a quota balancing, a state machine following only legal edges. Runabout ships a library of built-ins (`Invariants::…`) and lets you hand-write your own with `Invariant::make()`. See [Invariants](invariants.md).

## Trail

A **trail** is one concrete execution: an ordered list of the steps that actually ran, plus the seed and the values drawn along the way. The declared order is one trail; each shuffle is another. A completed trail is handed to any `onTrail()` observer, and a failing trail describes itself in the failure output. See [Trails & coverage](observability.md).

## Seed & shrinking

All randomness in a trail — which eligible step is picked next, and every `pick()`/`randomInt()` draw inside a step — flows from a single integer **seed**. Given the seed, the trail replays exactly. By default seeds are derived deterministically from the journey class and trail index, so CI runs are stable commit to commit.

When a trail fails, Runabout **shrinks** it: first minimising the trail's length (removing executions that don't change the outcome), then minimising the drawn values inside what survives. The reported failure leads with the smallest trail that still fails *the same way*. See [Reproducing failures](reproducing-failures.md).
