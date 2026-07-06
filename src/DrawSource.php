<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Random\Randomizer;

/**
 * Where an execution's data draws come from. Sits between Context and the raw
 * per-execution Randomizer (seed schema v2), so the value shrinker can swap in
 * a source that forces specific values while leaving ordering and everything
 * else untouched.
 *
 * A source records what it hands out, so the shrinker knows the baseline; an
 * execution that reaches for the raw randomizer() escape hatch is marked opaque
 * and left out of value shrinking (its draws still replay verbatim from the
 * stream — they just aren't candidates for minimisation).
 */
interface DrawSource
{
    /** A bounded integer draw — both randomInt() and pick()'s index go through here. */
    public function int(int $min, int $max): int;

    /** The raw randomizer escape hatch; taking it marks this execution value-opaque. */
    public function randomizer(): Randomizer;

    /** @return list<Draw> The draws handed out so far, in order. */
    public function draws(): array;

    /** Whether the raw randomizer was taken (so value shrinking must skip this execution). */
    public function isOpaque(): bool;
}
