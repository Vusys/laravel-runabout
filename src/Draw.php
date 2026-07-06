<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/**
 * One recorded bounded-integer draw: the value an execution pulled and the
 * domain it was pulled from. Both randomInt() and pick() (whose choice is an
 * index draw over [0, count-1]) funnel through this, so the value shrinker can
 * push any drawn value toward the low end of its own domain.
 */
final readonly class Draw
{
    public function __construct(
        public int $min,
        public int $max,
        public int $value,
    ) {}
}
