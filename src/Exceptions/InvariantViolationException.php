<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use RuntimeException;
use Throwable;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Step;

final class InvariantViolationException extends RuntimeException
{
    public static function make(Invariant $invariant, Step $step, Throwable $cause): self
    {
        return new self(
            sprintf('Invariant "%s" violated after step "%s": %s', $invariant->name(), $step->name(), $cause->getMessage()),
            0,
            $cause,
        );
    }
}
