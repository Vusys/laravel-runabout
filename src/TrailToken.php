<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/**
 * One execution's identity within a trail: which instance ran which step for
 * the nth time. The run index is the stream key under seed schema v2 — the nth
 * run of a step draws the same values wherever it lands in the trail — so a
 * token replays an execution's data verbatim, which is what makes an explicit
 * order (and therefore shrinking) reproduce a failing trail exactly.
 */
final readonly class TrailToken
{
    /**
     * @param  string|null  $label  The instance label ("A"/"B") when interleaved, null otherwise.
     * @param  string  $step  The step's declared name (unlabelled).
     * @param  int  $run  1-based run index of this step within its instance — the stream key.
     */
    public function __construct(
        public ?string $label,
        public string $step,
        public int $run,
    ) {}

    /** The step name carrying its instance label, matching Trail::steps(). */
    public function labelled(): string
    {
        return $this->label === null ? $this->step : sprintf('%s: %s', $this->label, $this->step);
    }

    /** @return array{0: string|null, 1: string, 2: int} The compact artifact form: [label, step, run]. */
    public function toArray(): array
    {
        return [$this->label, $this->step, $this->run];
    }

    /** @param array{0: string|null, 1: string, 2: int} $token */
    public static function fromArray(array $token): self
    {
        return new self($token[0], $token[1], $token[2]);
    }
}
