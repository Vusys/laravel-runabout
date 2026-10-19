<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Illuminate\Container\Container;
use Illuminate\Log\Context\Repository as ContextRepository;
use Illuminate\Support\Facades\Context as ContextFacade;
use Illuminate\Support\Facades\Facade;

/**
 * Framework state that outlives a request in a test process, where a
 * production request would start without it.
 *
 * @internal
 */
final class FrameworkState
{
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
}
