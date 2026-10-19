<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use ArrayObject;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Context;
use Vusys\Runabout\Support\FrameworkState;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/** The framework state Runabout clears between trails and requests, and how it measures it. */
final class FrameworkStateTest extends TestCase
{
    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('cache.default', 'array');
        $app['config']->set('cache.stores.limits', ['driver' => 'array']);
        $app['config']->set('cache.limiter', 'limits');
        $app->scoped('scoped.thing', fn (): ArrayObject => new ArrayObject);
    }

    public function test_array_caches_are_flushed_including_the_rate_limiters_own_store(): void
    {
        Cache::forever('a', 1);
        Cache::store('limits')->forever('b', 1);

        FrameworkState::flushArrayCaches();

        $this->assertNull(Cache::get('a'));
        $this->assertNull(Cache::store('limits')->get('b'));
    }

    public function test_the_fingerprint_counts_cache_entries_across_stores_and_context_keys_visible_and_hidden(): void
    {
        Cache::forever('a', 1);
        Cache::forever('b', 1);
        Cache::store('limits')->forever('c', 1);
        Context::add('visible', 1);
        Context::addHidden('hidden', 1);

        $this->assertSame(['array cache entries' => 3, 'Context keys' => 2], FrameworkState::fingerprint());
    }

    public function test_the_limiter_sharing_the_default_store_is_counted_once(): void
    {
        config()->set('cache.limiter');
        Cache::forever('a', 1);

        $this->assertSame(1, FrameworkState::fingerprint()['array cache entries']);
    }

    public function test_request_state_clearing_flushes_context_and_forgets_scoped_instances(): void
    {
        Context::add('visible', 1);
        $before = resolve('scoped.thing');

        FrameworkState::flushRequestState();

        $this->assertSame([], Context::all());
        $this->assertNotSame($before, resolve('scoped.thing'));
    }

    public function test_request_state_clearing_flushes_the_context_instance_already_handed_out(): void
    {
        $held = resolve(ContextRepository::class);
        Context::add('visible', 1);

        FrameworkState::flushRequestState();

        $this->assertSame([], $held->all());
    }

    public function test_after_clearing_the_context_facade_and_container_agree(): void
    {
        Context::add('before', 1);
        FrameworkState::flushRequestState();

        Context::add('after', 1);

        $this->assertTrue(resolve(ContextRepository::class)->has('after'));
    }

    public function test_guards_are_logged_out(): void
    {
        Auth::setUser(new User(['name' => 'ana']));

        FrameworkState::forgetGuards();

        $this->assertTrue(Auth::guest());
    }

    public function test_a_container_without_cache_or_auth_is_left_alone(): void
    {
        $app = Container::getInstance();
        Container::setInstance(new Container);

        try {
            FrameworkState::flushArrayCaches();
            FrameworkState::forgetGuards();

            $this->assertSame(0, FrameworkState::fingerprint()['array cache entries']);
        } finally {
            Container::setInstance($app);
        }
    }
}
