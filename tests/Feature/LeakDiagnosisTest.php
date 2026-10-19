<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context as LaravelContext;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/** Swallows STDERR so the leak check's warning can be asserted on (see PendingJourneyCoverageStderrFilter). */
final class LeakDiagnosisStderrFilter extends \php_user_filter
{
    public static string $buffer = '';

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $before = strlen(self::$buffer);
            self::$buffer .= $bucket->data;
            $consumed += strlen(self::$buffer) - $before;
        }

        return PSFS_PASS_ON;
    }
}

/**
 * Replays run in the same process as the trails before them, so state a
 * rollback doesn't reach can make a short trail fail only because of what ran
 * earlier. The shrinker then says so, naming what it had to clear.
 */
final class LeakDiagnosisTest extends TestCase
{
    use RunsJourneys;

    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/whoami', fn (Request $request): JsonResponse => new JsonResponse(['name' => $request->user()?->getAttribute('name')]));
    }

    public function test_a_failure_that_needs_leftover_cache_entries_is_diagnosed(): void
    {
        $message = $this->failureOf($this->journey($this->accumulating(
            fn () => Cache::increment('hits'),
            fn () => Assert::assertLessThan(4, Cache::get('hits')),
        ))->shuffles(5));

        $this->assertStringContainsString('Invariant "bounded" violated after step', $message);
        $this->assertStringEndsWith(
            "\nThe minimal trail passed when replayed after flushing the array cache store, so the failure depends on state that earlier trails or replays left behind, and the replay line above may not reproduce it on its own. Cached values or rate-limiter counters are leaking between trails; flush them after each trail with resetExternal(fn () => Cache::flush()).",
            $message,
        );
    }

    public function test_a_failure_that_needs_leftover_context_is_diagnosed(): void
    {
        $message = $this->failureOf($this->journey($this->accumulating(
            fn () => LaravelContext::add('seen '.count(LaravelContext::all()), true),
            fn () => Assert::assertLessThan(4, count(LaravelContext::all())),
        ))->shuffles(5));

        $this->assertStringContainsString('passed when replayed after clearing Laravel Context and scoped instances', $message);
        $this->assertStringEndsWith('Request-scoped state is leaking between requests; clear it in aroundStep() before each step.', $message);
    }

    public function test_a_failure_that_needs_a_leftover_login_is_diagnosed(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('look around')->act(fn (): null => null),
                    Step::make('log in as a guest')->act(function (): void {
                        Assert::assertTrue(Auth::guest(), 'Already logged in');
                        Auth::setUser(new User(['name' => 'ana']));
                    }),
                ];
            }
        };

        $message = $this->failureOf($this->journey($journey)->shuffles(3));

        $this->assertStringContainsString('passed when replayed after logging out every auth guard', $message);
        $this->assertStringEndsWith('A login is leaking between trails; authenticate through actors rather than in setUp().', $message);
    }

    public function test_a_failure_that_needs_a_stale_actor_user_is_diagnosed(): void
    {
        $ana = User::query()->create(['name' => 'ana']);

        $journey = new class($ana) extends Journey
        {
            public function __construct(private readonly User $ana) {}

            public function actors(): array
            {
                return ['ana' => $this->ana];
            }

            public function steps(): array
            {
                return [
                    Step::make('look around')->act(fn (): null => null),
                    Step::make('rename in memory')->act(function (Context $ctx): void {
                        $user = $ctx->as('ana')->user();
                        assert($user instanceof User);
                        $name = $user->getAttribute('name');
                        assert(is_string($name));
                        $user->setAttribute('name', $name.'!');
                        $ctx->as('ana')->getJson('/whoami');
                    })->assert(function (Context $ctx): void {
                        $name = $ctx->lastResponse()->json('name');
                        Assert::assertIsString($name);
                        Assert::assertLessThan(6, strlen($name));
                    }),
                ];
            }
        };

        $message = $this->failureOf($this->journey($journey)->shuffles(5));

        $this->assertStringContainsString("passed when replayed after reloading the actors' users", $message);
        $this->assertStringEndsWith("An actor's user object carries state from earlier trails; call freshActors().", $message);
    }

    public function test_a_journey_without_an_http_driver_is_still_diagnosed(): void
    {
        $journey = new PendingJourney($this->accumulating(
            fn () => Cache::increment('hits'),
            fn () => Assert::assertLessThan(4, Cache::get('hits')),
        ), $this->wrapTrail(...));

        $this->assertStringContainsString('passed when replayed after flushing the array cache store', $this->failureOf($journey->shuffles(5)));
    }

    public function test_a_trail_that_is_no_longer_viable_after_clearing_counts_as_passing(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('open the gate')->act(fn () => Cache::forever('gate', true)),
                    Step::make('through the gate')
                        ->when(fn (): bool => Cache::get('gate') === true)
                        ->repeatable()
                        ->act(fn () => Cache::increment('passes'))
                        ->assert(fn () => Assert::assertLessThan(3, Cache::get('passes'))),
                ];
            }
        };

        $this->assertStringContainsString('passed when replayed after flushing the array cache store', $this->failureOf($this->journey($journey)->shuffles(5)));
    }

    public function test_a_failure_that_cannot_be_shrunk_is_reported_as_it_happened(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('first')->act(fn (Context $ctx): mixed => $ctx->remember('first', true)),
                    Step::make('second')->after('first')->act(fn (Context $ctx) => Assert::assertFalse($ctx->get('first'), 'needs both steps')),
                ];
            }
        };

        $message = $this->failureOf($this->journey($journey)->shuffles(0));

        $this->assertStringStartsWith('Journey ', $message);
        $this->assertStringNotContainsString('Shrunk', $message);
    }

    public function test_the_leak_check_ignores_state_that_was_there_before_the_run(): void
    {
        Cache::forever('warmed', true);
        putenv('RUNABOUT_LEAK_CHECK=1');

        try {
            $output = $this->captureStderr(fn () => $this->journey($this->accumulating(fn (): null => null, fn (): null => null))->shuffles(3)->run());
        } finally {
            putenv('RUNABOUT_LEAK_CHECK');
        }

        $this->assertSame('', $output);
    }

    public function test_a_genuine_failure_gets_no_leak_note(): void
    {
        $message = $this->failureOf($this->journey($this->accumulating(
            fn (): null => null,
            fn () => Assert::fail('always'),
        ))->shuffles(2));

        $this->assertStringNotContainsString('passed when replayed after', $message);
    }

    public function test_the_leak_check_warns_once_when_a_trail_starts_with_leftover_state(): void
    {
        putenv('RUNABOUT_LEAK_CHECK=1');

        try {
            $output = $this->captureStderr(fn () => $this->journey($this->accumulating(fn () => Cache::forever(uniqid('hit ', true), true), fn (): null => null))->shuffles(4)->run());
        } finally {
            putenv('RUNABOUT_LEAK_CHECK');
        }

        $this->assertMatchesRegularExpression('/^Runabout leak check \(.+\): trail 2 started with state earlier trails left behind: array cache entries 0 → \d+\. A rollback doesn\'t reset this, so trails can depend on what ran before them\.\n$/', $output);
    }

    public function test_the_leak_check_is_quiet_when_nothing_leaks_or_it_is_off(): void
    {
        putenv('RUNABOUT_LEAK_CHECK=1');

        try {
            $quiet = $this->captureStderr(fn () => $this->journey($this->accumulating(fn (): null => null, fn (): null => null))->shuffles(3)->run());
        } finally {
            putenv('RUNABOUT_LEAK_CHECK');
        }

        $off = $this->captureStderr(fn () => $this->journey($this->accumulating(fn () => Cache::increment('hits'), fn (): null => null))->shuffles(3)->run());

        $this->assertSame('', $quiet);
        $this->assertSame('', $off);
    }

    /**
     * A two-step journey whose steps both run $act, checked by $check after every step.
     *
     * @param  Closure(): mixed  $act
     * @param  Closure(): mixed  $check
     */
    private function accumulating(Closure $act, Closure $check): Journey
    {
        return new class($act, $check) extends Journey
        {
            /**
             * @param  Closure(): mixed  $act
             * @param  Closure(): mixed  $check
             */
            public function __construct(private readonly Closure $act, private readonly Closure $check) {}

            public function steps(): array
            {
                return [
                    Step::make('first')->act(fn () => ($this->act)()),
                    Step::make('second')->act(fn () => ($this->act)()),
                ];
            }

            public function invariants(): array
            {
                return [Invariant::make('bounded', function (): void {
                    ($this->check)();
                })];
            }
        };
    }

    private function failureOf(PendingJourney $journey): string
    {
        try {
            $journey->run();
        } catch (JourneyFailedException $failure) {
            return $failure->getMessage();
        }

        $this->fail('The journey was expected to fail.');
    }

    /** @param Closure(): void $fn */
    private function captureStderr(Closure $fn): string
    {
        static $registered = false;

        if (! $registered) {
            stream_filter_register('leak-diagnosis-capture', LeakDiagnosisStderrFilter::class);
            $registered = true;
        }

        LeakDiagnosisStderrFilter::$buffer = '';
        $handle = stream_filter_append(STDERR, 'leak-diagnosis-capture');

        try {
            $fn();
        } finally {
            if ($handle !== false) {
                stream_filter_remove($handle);
            }
        }

        return LeakDiagnosisStderrFilter::$buffer;
    }
}
