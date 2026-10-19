<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Illuminate\Cache\ArrayStore;
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
    /** Flush the in-memory cache stores the app uses: the default store and the rate limiter's. */
    public static function flushArrayCaches(): void
    {
        foreach (self::arrayStores() as $store) {
            $store->flush();
        }
    }

    /** Forget Laravel Context and scoped container instances, as a fresh production request starts without them. */
    public static function flushRequestState(): void
    {
        // Flush the Context the facade holds (it caches what it resolved, so
        // after an earlier forget the container may already hold a newer
        // instance), forget scoped instances, then let the facade re-resolve.
        ContextFacade::flush();
        Container::getInstance()->forgetScopedInstances();
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
            /** @var array<string, mixed> $storage */
            $storage = (new ReflectionProperty(ArrayStore::class, 'storage'))->getValue($store);
            $entries += count($storage);
        }

        return [
            'array cache entries' => $entries,
            'Context keys' => count(ContextFacade::all()) + count(ContextFacade::allHidden()),
        ];
    }

    /** @return array<int, ArrayStore> Keyed by object id, so a store the limiter shares with the default appears once. */
    private static function arrayStores(): array
    {
        $app = Container::getInstance();

        if (! $app->bound('cache')) {
            return [];
        }

        /** @var string|null $limiter */
        $limiter = $app->make('config')->get('cache.limiter');
        $stores = [];

        // The rate limiter uses the default store unless cache.limiter names another.
        foreach ([null, $limiter] as $name) {
            $store = $app->make('cache')->store($name)->getStore();

            if ($store instanceof ArrayStore) {
                $stores[spl_object_id($store)] = $store;
            }
        }

        return $stores;
    }
}
