<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Illuminate\Cache\ArrayStore;
use Illuminate\Cache\Repository;
use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context as ContextFacade;
use Illuminate\Support\Facades\Facade;
use ReflectionProperty;

/**
 * Framework state that outlives a trail or a request in a test process: a
 * database rollback doesn't reach it, so it can make one trail's behaviour
 * depend on what earlier trails did. Clearing is limited to what is safe in
 * tests: only in-memory (array) cache stores are flushed, never a real one.
 *
 * @internal
 */
final class FrameworkState
{
    /** Flush every in-memory cache store the app has resolved: the default store and the rate limiter's. */
    public static function flushArrayCaches(): void
    {
        foreach (self::arrayStores() as $store) {
            $store->flush();
        }
    }

    /** Forget Laravel Context and scoped container instances, as a fresh production request starts without them. */
    public static function flushRequestState(): void
    {
        $app = Container::getInstance();

        // Flush the Context the facade holds (it caches what it resolved, so
        // after an earlier forget the container may already hold a newer
        // instance), forget scoped instances, then let the facade re-resolve.
        if ($app->bound(ContextRepository::class)) {
            ContextFacade::flush();
        }

        $app->forgetScopedInstances();
        Facade::clearResolvedInstance(ContextRepository::class);
    }

    /** Log every resolved auth guard out. */
    public static function forgetGuards(): void
    {
        $app = Container::getInstance();

        if ($app->bound('auth')) {
            $app->make('auth')->forgetGuards();
        }
    }

    /**
     * Cheap counts of state that should be empty when a trail starts.
     *
     * @return array{'array cache entries': int, 'Context keys': int}
     */
    public static function fingerprint(): array
    {
        $entries = 0;

        foreach (self::arrayStores() as $store) {
            $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);
            $entries += is_array($storage) ? count($storage) : 0;
        }

        $app = Container::getInstance();
        $context = 0;

        if ($app->bound(ContextRepository::class)) {
            $repository = $app->make(ContextRepository::class);
            $context = count($repository->all()) + count($repository->allHidden());
        }

        return ['array cache entries' => $entries, 'Context keys' => $context];
    }

    /** @return list<ArrayStore> */
    private static function arrayStores(): array
    {
        $app = Container::getInstance();

        if (! $app->bound('cache') || ! $app->bound('config')) {
            return [];
        }

        $cache = $app->make('cache');

        $stores = [];

        foreach (array_unique(array_filter([$app->make('config')->get('cache.default'), $app->make('config')->get('cache.limiter')], is_string(...))) as $name) {
            $repository = $cache->store($name);
            $store = $repository instanceof Repository ? $repository->getStore() : null;

            if ($store instanceof ArrayStore) {
                $stores[] = $store;
            }
        }

        return $stores;
    }
}
