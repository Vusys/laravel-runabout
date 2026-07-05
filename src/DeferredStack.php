<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;

/**
 * The trail's teardown stack. One per trail and shared by every journey
 * instance in it, so teardowns unwind in reverse execution order across the
 * whole trail — even when the executions interleave instances.
 */
final class DeferredStack
{
    /** @var list<Closure> */
    private array $deferred = [];

    public function push(Closure $fn): void
    {
        $this->deferred[] = $fn;
    }

    /** @return list<Closure> in LIFO order; the stack is emptied. */
    public function drain(): array
    {
        $drained = array_reverse($this->deferred);
        $this->deferred = [];

        return $drained;
    }
}
