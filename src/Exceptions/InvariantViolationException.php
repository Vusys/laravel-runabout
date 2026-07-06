<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use RuntimeException;
use Throwable;

final class InvariantViolationException extends RuntimeException
{
    /**
     * @param  string  $invariant  The invariant's name, labelled with its owning instance when interleaved.
     * @param  string  $step  The step's name, labelled with its acting instance when interleaved.
     */
    private function __construct(
        string $message,
        public readonly string $invariant,
        public readonly string $step,
        Throwable $cause,
    ) {
        parent::__construct($message, 0, $cause);
    }

    public static function make(string $invariant, string $step, Throwable $cause): self
    {
        return new self(
            sprintf('Invariant "%s" violated after step "%s": %s', $invariant, $step, $cause->getMessage()),
            $invariant,
            $step,
            $cause,
        );
    }
}
