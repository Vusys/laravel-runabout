<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/** The concrete ordered sequence of steps one execution took. */
final class Trail
{
    /** @var list<string> */
    private array $steps = [];

    public function __construct(
        private readonly int $seed,
        private readonly bool $shuffled,
    ) {
    }

    public function record(string $step): void
    {
        $this->steps[] = $step;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    public function isShuffled(): bool
    {
        return $this->shuffled;
    }

    /** @return list<string> */
    public function steps(): array
    {
        return $this->steps;
    }

    public function describe(): string
    {
        if ($this->steps === []) {
            return '  (no steps ran)';
        }

        $lines = [];
        $last = count($this->steps) - 1;

        foreach ($this->steps as $index => $step) {
            $marker = $index === $last ? '>' : ' ';
            $lines[] = sprintf('%s %2d. %s', $marker, $index + 1, $step);
        }

        return implode(PHP_EOL, $lines);
    }
}
