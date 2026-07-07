# Installation

## Requirements

- **PHP** 8.3 or newer
- **Laravel** 11, 12, or 13 (`illuminate/database` and `illuminate/support` `^11.0 | ^12.0 | ^13.0`)

## Install

Runabout is a testing tool, so install it as a dev dependency:

```bash
composer require --dev vusys/laravel-runabout
```

That is the entire setup. **There is no service provider to register, no config file to publish, and no migrations to run** — you will not run `php artisan vendor:publish`. Runabout is consumed purely as a test-only trait mixed into a test case, and everything is configured fluently in code or through [environment variables](environment.md).

## Wiring it into a test

Add the `RunsJourneys` trait to any Laravel or [Testbench](https://github.com/orchestral/testbench) test case:

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

The trait gives the test case a small set of entry points — `journey()`, `interleave()`, and hooks like `wrapTrail()` — that return a fluent runner you configure and then `run()`.

Next, define your first journey in the [Quick start](quick-start.md).
