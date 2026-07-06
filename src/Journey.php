<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;

abstract class Journey
{
    /** @return list<Step> */
    abstract public function steps(): array;

    /**
     * Named actors to register on this journey's context at the start of every
     * trail, so steps can make authenticated requests through them
     * ($ctx->as('manager')->postJson(...)) without a setup step. Return a map of
     * actor name to the user it authenticates as.
     *
     * Actors live on the per-trail context, so they must be re-registered each
     * trail; declaring them here does that automatically. The users must exist
     * before the run (create them in the test and pass them to the journey) —
     * anything created inside a trail is rolled back before the next one, so
     * register those with $ctx->actingAs() in a step instead.
     *
     * @return array<string, Authenticatable>
     */
    public function actors(): array
    {
        return [];
    }

    /**
     * Checked after every step, whichever step it was.
     *
     * @return list<Invariant>
     */
    public function invariants(): array
    {
        return [];
    }

    /**
     * Wraps every execution belonging to this journey: each step (act +
     * assertions) and each check of this journey's invariants. Override it to
     * establish per-instance environment before the execution runs — the
     * canonical use is re-establishing session-state tenancy in interleaved
     * trails, where instances share one session and whose turn it is changes
     * step to step. Declared once here, it cannot be forgotten on an
     * individual step, and it covers invariant checks too — those run after
     * every step of *any* instance, so they cannot rely on whatever
     * environment the last act left behind.
     *
     * The override must invoke $execution (a run that returns without calling
     * it is reported as an invalid journey) and must let its exceptions
     * propagate — swallowing them would silently pass failing trails.
     * Teardowns are not wrapped; a deferred closure that needs per-instance
     * environment should establish it itself.
     *
     * @param  Closure(): void  $execution
     */
    public function aroundStep(Closure $execution, Context $context): void
    {
        $execution();
    }
}
