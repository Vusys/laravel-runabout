<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/** The concrete ordered sequence of steps one execution took. */
final class Trail
{
    /** @var list<string> */
    private array $steps = [];

    /** @param 'canonical'|'shuffled'|'repeat-heavy'|'exhaustive' $mode */
    public function __construct(
        private readonly int $seed,
        private readonly string $mode,
    ) {}

    public function record(string $step): void
    {
        $this->steps[] = $step;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /** @return 'canonical'|'shuffled'|'repeat-heavy'|'exhaustive' */
    public function mode(): string
    {
        return $this->mode;
    }

    public function isShuffled(): bool
    {
        return $this->mode !== 'canonical';
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }

    /** @param bool $markLast Point at the last step with a ">" marker — where the failure output points at the failing step. */
    public function describe(bool $markLast = true): string
    {
        if ($this->steps === []) {
            return '  (no steps ran)';
        }

        $lines = [];
        $runs = [];
        $last = count($this->steps) - 1;

        foreach ($this->steps as $index => $step) {
            $runs[$step] = ($runs[$step] ?? 0) + 1;
            $marker = $markLast && $index === $last ? '>' : ' ';
            $label = $runs[$step] > 1 ? sprintf('%s (run %d)', $step, $runs[$step]) : $step;
            $lines[] = sprintf('%s %2d. %s', $marker, $index + 1, $label);
        }

        return implode(PHP_EOL, $lines);
    }
}
