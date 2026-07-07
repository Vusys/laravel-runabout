# Runabout

[![Tests](https://github.com/Vusys/laravel-runabout/actions/workflows/tests.yml/badge.svg)](https://github.com/Vusys/laravel-runabout/actions/workflows/tests.yml) [![codecov](https://codecov.io/gh/Vusys/laravel-runabout/graph/badge.svg)](https://codecov.io/gh/Vusys/laravel-runabout) [![tests](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-runabout/badges/tests.json)](https://github.com/Vusys/laravel-runabout/actions/workflows/tests.yml) [![assertions](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-runabout/badges/assertions.json)](https://github.com/Vusys/laravel-runabout/actions/workflows/tests.yml) [![test LOC](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-runabout/badges/test-ratio.json)](tests/) [![CI matrix](https://img.shields.io/endpoint?url=https://raw.githubusercontent.com/Vusys/laravel-runabout/badges/matrix.json)](.github/workflows/tests.yml) [![Bencher](https://img.shields.io/badge/Bencher-tracked-FD6F1B?logo=data:image/svg+xml;base64,PHN2ZyB4bWxucz0iaHR0cDovL3d3dy53My5vcmcvMjAwMC9zdmciIHZpZXdCb3g9IjAgMCAyNCAyNCIgZmlsbD0id2hpdGUiPjxwYXRoIGQ9Ik0xMiAyTDMgN3YxMGw5IDUgOS01VjdaIi8+PC9zdmc+)](https://bencher.dev/perf/laravel-runabout) [![Mutation testing](https://img.shields.io/endpoint?style=flat&url=https://badge-api.stryker-mutator.io/github.com/Vusys/laravel-runabout/master)](https://dashboard.stryker-mutator.io/reports/github.com/Vusys/laravel-runabout/master) [![OpenSSF Scorecard](https://api.scorecard.dev/projects/github.com/Vusys/laravel-runabout/badge)](https://scorecard.dev/viewer/?uri=github.com/Vusys/laravel-runabout) [![PHP](https://img.shields.io/badge/php-%5E8.3-777BB4?logo=php&logoColor=white)](composer.json) [![Laravel](https://img.shields.io/badge/laravel-11%20%7C%2012%20%7C%2013-FF2D20?logo=laravel)](composer.json) [![PHPStan](https://img.shields.io/badge/PHPStan-level%209-brightgreen.svg)](phpstan.neon) [![Rector](https://img.shields.io/badge/Rector-passing-brightgreen.svg)](rector.php) [![Code Style: Pint](https://img.shields.io/badge/code%20style-Laravel%20Pint-FF2D20.svg?logo=laravel)](https://github.com/laravel/pint) [![License](https://img.shields.io/badge/license-MIT-green.svg)](LICENSE)

Journey testing for Laravel: define the steps of a user journey once, and Runabout executes them in randomized-but-deterministic orders, checking your invariants after every step. It exists to catch the bugs that unit, feature, and browser tests all miss — the ones that only appear when real users do things in an order you never thought to test.

**📚 Full documentation: [vusys.github.io/laravel-runabout](https://vusys.github.io/laravel-runabout/)**

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

One journey definition becomes twenty-six executions: the declared order, then twenty-five seeded shuffles that respect your constraints. When a shuffle fails, the seed reproduces the exact trail — and Runabout shrinks it to the smallest reproduction before reporting it.

## Requirements

- PHP 8.3+
- Laravel 11, 12, or 13

## Installation

```bash
composer require --dev vusys/laravel-runabout
```

No service provider to register and nothing to publish — Runabout is a test-only trait you mix into a test case.

## Quick start

Add the `RunsJourneys` trait to a test case, define a `Journey`, and run it. The [Quick start guide](https://vusys.github.io/laravel-runabout/quick-start/) walks through a complete example; the short version is:

```php
use Vusys\Runabout\RunsJourneys;

final class PostLifecycleTest extends TestCase
{
    use RunsJourneys;

    public function test_post_lifecycle(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
    }
}
```

## Documentation

Full docs live at **[vusys.github.io/laravel-runabout](https://vusys.github.io/laravel-runabout/)**. By topic:

| Topic | Page |
|---|---|
| Why it exists and how it works | [Introduction](docs/introduction.md) |
| Installing and wiring it in | [Installation](docs/installation.md) |
| A complete worked example | [Quick start](docs/quick-start.md) |
| The vocabulary | [Core concepts](docs/concepts.md) |
| The step DSL | [Defining steps](docs/steps.md) |
| Trail state and seeded randomness | [The context](docs/context.md) |
| Driving the app as named users | [Actors & HTTP](docs/actors-http.md) |
| Shuffling the clock | [Time travel](docs/time-travel.md) |
| Properties checked after every step | [Invariants](docs/invariants.md) |
| shuffles / repeatHeavy / exhaustive / seed | [Execution modes](docs/execution-modes.md) |
| Cross-actor and multi-tenant bugs | [Interleaving journeys](docs/interleaving.md) |
| Fresh state between trails | [Resetting state](docs/database-resets.md) |
| Seeds, artifacts, and shrinking | [Reproducing failures](docs/reproducing-failures.md) |
| Seeing what the shuffler explored | [Trails & coverage](docs/observability.md) |
| The `RUNABOUT_*` knobs | [Environment variables](docs/environment.md) |

## License

MIT. See [LICENSE](LICENSE).
