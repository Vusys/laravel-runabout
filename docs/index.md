# Runabout

Journey testing for Laravel: define the steps of a user journey once, and Runabout executes them in randomised-but-deterministic orders, checking your invariants after every step. It exists to catch the bugs that unit, feature, and browser tests all miss — the ones that only appear when real users do things in an order you never thought to test.

```php
final class PostLifecycleTest extends TestCase
{
    use RunsJourneys;

    public function test_post_lifecycle(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
    }
}
```

One journey definition becomes twenty-six executions: the declared order, then twenty-five seeded shuffles that respect your constraints. When a shuffle fails, the seed reproduces the exact trail.

## Highlights

- **One definition, many orderings.** Declare the steps of a journey once; Runabout runs them in the declared order, then in N seeded shuffles that honour your constraints.
- **Deterministic and replayable.** All randomness flows from a single integer seed, so any failing trail replays exactly — down to the drawn values.
- **Automatic shrinking.** A failing trail is minimised to the shortest reproduction (then its drawn values are minimised too) before it's reported, so you read four steps, not twenty-three.
- **Invariants after every step.** Recompute what must always hold — cached columns, quotas, legal state transitions, soft-delete integrity, uniqueness — and Runabout checks it after each step.
- **HTTP-driven with named actors.** Drive the app over its real routes as authenticated participants, carrying session/tenancy state per actor.
- **Interleave mode.** Merge-shuffle multiple journeys into one trail to surface cross-actor and multi-tenant state leaks nothing else reaches.
- **No config, no service provider.** A single dev dependency and a test-case trait; nothing to publish.

## Where to go

| | |
|---|---|
| New here? | [Introduction](introduction.md) → [Installation](installation.md) → [Quick start](quick-start.md) |
| The mental model | [Core concepts](concepts.md) |
| Building journeys | [Defining steps](steps.md), [The context](context.md), [Invariants](invariants.md) |
| Driving the app | [Actors & HTTP](actors-http.md), [Time travel](time-travel.md) |
| Running them | [Execution modes](execution-modes.md), [Interleaving journeys](interleaving.md), [Resetting state](database-resets.md) |
| When something fails | [Reproducing failures](reproducing-failures.md) |
| Measuring the run | [Trails & coverage](observability.md), [Environment variables](environment.md) |

## Requirements

PHP `^8.3` · Laravel `11 / 12 / 13`

```bash
composer require --dev vusys/laravel-runabout
```
