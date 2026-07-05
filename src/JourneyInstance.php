<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/** @internal One journey's slice of an (optionally interleaved) trail. */
final readonly class JourneyInstance
{
    /**
     * @param  string|null  $label  Trail prefix; null for single-instance runs.
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    public function __construct(
        public Journey $journey,
        public ?string $label,
        public array $steps,
        public array $invariants,
        public Context $context,
    ) {}

    public function describe(): string
    {
        return $this->label === null
            ? $this->journey::class
            : sprintf('%s=%s', $this->label, $this->journey::class);
    }

    /** A step or invariant name carrying this instance's trail label; unchanged for single-instance runs. */
    public function labelled(string $name): string
    {
        return $this->label === null ? $name : sprintf('%s: %s', $this->label, $name);
    }
}
