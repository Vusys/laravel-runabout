<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Random\Randomizer;

/**
 * Replays a fixed list of forced values for one execution during the value
 * shrink pass. Each forced value is clamped to the draw's declared domain, so a
 * forced value can never fall out of range. Draws beyond the script fall back
 * to the keyed stream — that fallback is what preserves position-independence:
 * scripting one execution's values never perturbs another's, and a candidate
 * whose control flow draws more than the ledger recorded still runs.
 */
final class ScriptedDrawSource implements DrawSource
{
    private int $cursor = 0;

    private bool $opaque = false;

    /** @var list<Draw> */
    private array $draws = [];

    /** @param  list<int>  $script  Forced values, consumed in draw order. */
    public function __construct(
        private readonly array $script,
        private readonly Randomizer $fallback,
    ) {}

    public function int(int $min, int $max): int
    {
        if ($this->cursor < count($this->script)) {
            $value = max($min, min($max, $this->script[$this->cursor]));
            $this->cursor++;
        } else {
            $value = $this->fallback->getInt($min, $max);
        }

        $this->draws[] = new Draw($min, $max, $value);

        return $value;
    }

    public function randomizer(): Randomizer
    {
        $this->opaque = true;

        return $this->fallback;
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
