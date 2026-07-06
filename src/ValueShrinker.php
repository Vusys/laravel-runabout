<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;

/**
 * Minimises the drawn values inside an already-length-minimal trail, so the
 * counterexample is concrete: not "open two deals of random amounts and close
 * the larger" but "open a deal of 51, open a deal of 50, close the larger".
 *
 * Runs after sequence shrinking, over the per-token draw ledgers recorded from
 * the minimal trail. Each draw is pushed toward the low end of its own domain
 * by binary search — halving toward `min` for ints, toward index 0 (the first
 * option) for picks — and a lowered value is kept only if the trail still fails
 * the same way (the reproduces() probe, gated by the same FailureSignature the
 * sequence shrinker uses). The exact-oracle discipline is what lets it stop at
 * a boundary: if 51 -> 50 makes the candidate pass, 51 stands.
 *
 * Deterministic and budget-capped, structurally identical to TrailShrinker but
 * over values instead of positions.
 */
final class ValueShrinker
{
    private int $replays = 0;

    /**
     * @param  Closure(array<int, array<int, int>>): bool  $reproduces  Whether the trail with these forced draws fails the same way.
     * @param  int  $budget  Maximum candidate replays.
     */
    public function __construct(
        private readonly Closure $reproduces,
        private readonly int $budget = 200,
    ) {}

    /**
     * @param  array<int, list<Draw>>  $baseline  Recorded draws per token index (value-opaque tokens omitted).
     * @return array{forced: array<int, array<int, int>>, replays: int}
     */
    public function shrink(array $baseline): array
    {
        $this->replays = 0;

        // Start every draw pinned at what it actually drew — replaying this is
        // the minimal trail verbatim, so it reproduces by construction.
        $forced = [];
        foreach ($baseline as $token => $draws) {
            $forced[$token] = array_map(fn (Draw $draw): int => $draw->value, $draws);
        }

        foreach ($baseline as $token => $draws) {
            foreach ($draws as $position => $draw) {
                if ($this->replays >= $this->budget) {
                    break 2;
                }

                $forced[$token][$position] = $this->minimise($forced, $token, $position, $draw->min);
            }
        }

        return ['forced' => $forced, 'replays' => $this->replays];
    }

    /**
     * Binary-search the lowest value in [min, current] that still reproduces,
     * holding every other draw at its current forced value.
     *
     * @param  array<int, array<int, int>>  $forced
     */
    private function minimise(array $forced, int $token, int $position, int $min): int
    {
        $high = $forced[$token][$position]; // reproduces (it is the current best)

        if ($high <= $min) {
            return $high;
        }

        $low = $min;

        while ($low < $high && $this->replays < $this->budget) {
            $mid = intdiv($low + $high, 2);

            $candidate = $forced;
            $candidate[$token][$position] = $mid;

            $this->replays++;

            if (($this->reproduces)($candidate)) {
                $high = $mid;
            } else {
                $low = $mid + 1;
            }
        }

        return $high;
    }
}
