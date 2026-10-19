<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context as LaravelContext;
use Illuminate\Support\Facades\RateLimiter;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\DefaultHooksJourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\JourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\Models\User;

/**
 * A database rollback doesn't reach in-memory framework state, so without
 * isolateTrails() one trail's cache entries, rate-limiter hits, Context and
 * logins are still there for the next.
 */
final class IsolateTrailsTest extends JourneyTestCase
{
    /** @var list<Closure(): void> */
    private array $resets = [];

    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.disk', ['driver' => 'file', 'path' => sys_get_temp_dir().'/runabout-isolate-trails']);
    }

    #[\Override]
    protected function journeyStateResets(): array
    {
        return $this->resets;
    }

    public function test_cache_entries_start_fresh_each_trail(): void
    {
        $journey = $this->perTrail(fn () => Cache::increment('hits'), fn () => Assert::assertLessThanOrEqual(2, Cache::get('hits')));

        $this->journey($journey)->isolateTrails()->shuffles(5)->run();

        $this->assertStringContainsString('Invariant "per trail" violated', $this->failureOf($this->journey($journey)->shuffles(5)));
    }

    public function test_rate_limiter_hits_start_fresh_each_trail(): void
    {
        $journey = $this->perTrail(fn () => RateLimiter::hit('login'), fn () => Assert::assertLessThanOrEqual(2, RateLimiter::attempts('login')));

        $this->journey($journey)->isolateTrails()->shuffles(5)->run();

        $this->assertStringContainsString('Invariant "per trail" violated', $this->failureOf($this->journey($journey)->shuffles(5)));
    }

    public function test_context_starts_empty_each_trail(): void
    {
        $journey = $this->perTrail(fn () => LaravelContext::push('seen', true), fn () => Assert::assertLessThanOrEqual(2, count((array) LaravelContext::get('seen', []))));

        $this->journey($journey)->isolateTrails()->shuffles(5)->run();

        $this->assertStringContainsString('Invariant "per trail" violated', $this->failureOf($this->journey($journey)->shuffles(5)));
    }

    public function test_a_login_does_not_carry_into_the_next_trail(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('log in as a guest')->act(function (): void {
                        Assert::assertTrue(Auth::guest(), 'Already logged in');
                        Auth::setUser(new User(['name' => 'ana']));
                    }),
                ];
            }
        };

        $this->journey($journey)->isolateTrails()->shuffles(3)->run();

        $this->assertStringContainsString('Already logged in', $this->failureOf($this->journey($journey)->shuffles(3)));
    }

    public function test_a_real_cache_store_is_never_flushed(): void
    {
        Cache::store('disk')->forever('kept', 'yes');

        try {
            $this->journey($this->perTrail(fn (): null => null, fn (): null => null))->isolateTrails()->shuffles(2)->run();

            $this->assertSame('yes', Cache::store('disk')->get('kept'));
        } finally {
            Cache::store('disk')->flush();
        }
    }

    public function test_the_default_state_resets_are_none_and_subclasses_can_reach_the_hook(): void
    {
        $test = new DefaultHooksJourneyTestCase('defaultStateResets');

        $this->assertSame([], $test->defaultStateResets());
    }

    public function test_state_resets_run_after_every_trail_without_isolate_trails(): void
    {
        $calls = 0;
        $this->resets = [function () use (&$calls): void {
            $calls++;
        }];

        $this->journey($this->perTrail(fn (): null => null, fn (): null => null))->shuffles(3)->run();

        $this->assertSame(4, $calls);
    }

    public function test_state_resets_also_run_for_failing_trails_and_shrink_replays(): void
    {
        $calls = 0;
        $this->resets = [function () use (&$calls): void {
            $calls++;
        }];

        // Fails after the second step, so the two-execution trail is shrunk.
        $this->failureOf($this->journey($this->perTrail(fn () => Cache::increment('replayed'), fn () => Assert::assertLessThan(2, Cache::get('replayed'))))->shuffles(0));

        $this->assertGreaterThan(1, $calls);
    }

    public function test_a_failing_reset_does_not_mask_the_trails_failure_but_surfaces_on_its_own(): void
    {
        $this->resets = [fn () => throw new RuntimeException('reset failed')];

        $this->assertStringContainsString('always', $this->failureOf($this->journey($this->perTrail(fn (): null => null, fn () => Assert::fail('always')))->shuffles(0)));

        try {
            $this->journey($this->perTrail(fn (): null => null, fn (): null => null))->shuffles(0)->run();
            $this->fail('Expected the reset failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame('reset failed', $caught->getMessage());
        }
    }

    /**
     * Two steps that each run $act, checked by $check after every step.
     *
     * @param  Closure(): mixed  $act
     * @param  Closure(): mixed  $check
     */
    private function perTrail(Closure $act, Closure $check): Journey
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
                return [Invariant::make('per trail', function (): void {
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
        } finally {
            // Leave nothing behind for the next assertion in the same test.
            Cache::flush();
            LaravelContext::flush();
            Auth::forgetGuards();
        }

        $this->fail('The journey was expected to fail.');
    }
}
