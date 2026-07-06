<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;

final readonly class Invariant
{
    /** @param Closure(Context): void $check */
    private function __construct(
        private string $name,
        private Closure $check,
        private bool $checkAtStart = false,
    ) {}

    /** @param Closure(Context): void $check */
    public static function make(string $name, Closure $check): self
    {
        return new self($name, $check);
    }

    /**
     * Also check this invariant once at the start of the trail, before any step
     * runs, so it observes the world's baseline — not only the state each step
     * leaves behind. The natural use is a state invariant on a row that exists
     * before the journey: without a baseline observation the invariant first
     * sees the row *after* the opening step, so that step's transition is
     * mistaken for the row's initial state. Opt in with a leading observation
     * step instead if the baseline should be an explicit part of the trail.
     */
    public function fromStart(): self
    {
        return new self($this->name, $this->check, true);
    }

    public function checksAtStart(): bool
    {
        return $this->checkAtStart;
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
