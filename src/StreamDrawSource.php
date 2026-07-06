<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Random\Randomizer;

/**
 * The default source: draws from the execution's keyed Mt19937 stream (seed
 * schema v2) and records each draw. This is what the runner installs per
 * execution on an ordinary run; recording is cheap and only read when a value
 * shrink pass needs the baseline.
 */
final class StreamDrawSource implements DrawSource
{
    /** @var list<Draw> */
    private array $draws = [];

    private bool $opaque = false;

    public function __construct(private readonly Randomizer $randomizer) {}

    public function int(int $min, int $max): int
    {
        $value = $this->randomizer->getInt($min, $max);

        $this->draws[] = new Draw($min, $max, $value);

        return $value;
    }

    public function randomizer(): Randomizer
    {
        $this->opaque = true;

        return $this->randomizer;
    }

    public function draws(): array
    {
        return $this->draws;
    }

    public function isOpaque(): bool
    {
        return $this->opaque;
    }
}
