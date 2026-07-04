<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;

final class Invariant
{
    /** @param Closure(Context): void $check */
    private function __construct(
        private readonly string $name,
        private readonly Closure $check,
    ) {
    }

    /** @param Closure(Context): void $check */
    public static function make(string $name, Closure $check): self
    {
        return new self($name, $check);
    }

    public function name(): string
    {
        return $this->name;
    }

    public function check(Context $context): void
    {
        ($this->check)($context);
    }
}
