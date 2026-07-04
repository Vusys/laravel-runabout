<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use RuntimeException;
use Throwable;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Trail;

final class JourneyFailedException extends RuntimeException
{
    private Trail $trail;

    public static function wrap(Journey $journey, Trail $trail, Throwable $cause): self
    {
        $mode = $trail->isShuffled() ? 'shuffled' : 'canonical order';
        $at = $trail->steps() === []
            ? 'before any step ran'
            : sprintf('at step %d ("%s")', count($trail->steps()), $trail->steps()[count($trail->steps()) - 1]);

        $message = sprintf(
            "Journey %s failed (%s, seed %d) %s.\nTrail:\n%s\n%s\nReplay with RUNABOUT_SEED=%d.",
            $journey::class,
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
