<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use RuntimeException;
use Throwable;
use Vusys\Runabout\Trail;

final class JourneyFailedException extends RuntimeException
{
    private Trail $trail;

    /** @param string $journeys The journey class, or "A=ClassA + B=ClassB" for interleaved runs. */
    public static function wrap(string $journeys, Trail $trail, Throwable $cause): self
    {
        $mode = $trail->mode() === 'canonical' ? 'canonical order' : $trail->mode();
        $at = $trail->steps() === []
            ? 'before any step ran'
            : sprintf('at step %d ("%s")', count($trail->steps()), $trail->steps()[count($trail->steps()) - 1]);

        $message = sprintf(
            "Journey %s failed (%s, seed %d) %s.\nTrail:\n%s\n%s\nReplay with RUNABOUT_SEED=%d.",
            $journeys,
            $mode,
            $trail->seed(),
            $at,
            $trail->describe(),
            $cause->getMessage(),
            $trail->seed(),
        );

        $exception = new self($message, 0, $cause);
        $exception->trail = $trail;

        return $exception;
    }

    public function trail(): Trail
    {
        return $this->trail;
    }
}
