<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;

/**
 * Minimises a failing trail to the shortest subsequence that still reproduces
 * the same failure. Delta-debugging with a polish pass: remove large chunks
 * first (a repeat-heavy trail is mostly removable padding), then sweep single
 * executions backward until a full pass removes nothing. The search itself
 * uses no randomness, so a given failing trail always shrinks to the same
 * result, and it is budget-capped because every candidate costs a full trail.
 *
 * It works over the trail's execution *positions* (a list of indices) and asks
 * the reproduces() probe whether a given subsequence still fails the same way.
 * Correctness comes entirely from that probe: a candidate is kept only if it
 * fails identically, and viability — a token whose step is unreachable once its
 * dependencies are removed — is the probe's concern too (a non-viable order
 * simply "does not reproduce").
 */
final class TrailShrinker
{
    private int $replays = 0;

    /**
     * @param  Closure(list<int>): bool  $reproduces  Whether the subsequence at these positions reproduces the original failure.
     * @param  int  $budget  Maximum candidate replays before returning the best subsequence found so far.
     */
    public function __construct(
        private readonly Closure $reproduces,
        private readonly int $budget = 200,
    ) {}

    /**
     * @param  list<int>  $positions  Every position of the failing trail, in order.
     * @return array{positions: list<int>, replays: int}
     */
    public function shrink(array $positions): array
    {
        $this->replays = 0;

        $best = $this->removeChunks($positions);
        $best = $this->sweepSingles($best);

        return ['positions' => $best, 'replays' => $this->replays];
    }

    /**
     * Coarse phase: partition into `granularity` contiguous chunks and try
     * dropping each. On a hit, ease the granularity and restart on the smaller
     * trail; on a full miss, double the granularity (finer chunks) until it
     * exceeds the trail length.
     *
     * @param  list<int>  $best
     * @return list<int>
     */
    private function removeChunks(array $best): array
    {
        $granularity = 2;

        while (count($best) >= 2 && $this->replays < $this->budget) {
            $length = count($best);
            $chunks = min($granularity, $length);
            $removed = false;

            for ($i = 0; $i < $chunks; $i++) {
                $start = intdiv($i * $length, $chunks);
                $stop = intdiv(($i + 1) * $length, $chunks);
                $candidate = $this->without($best, $start, $stop - $start);

                if ($candidate !== [] && $this->probe($candidate)) {
                    $best = $candidate;
                    $removed = true;

                    break;
                }

                if ($this->replays >= $this->budget) {
                    return $best;
                }
            }

            if ($removed) {
                $granularity = max(2, $granularity - 1);
            } elseif ($granularity >= count($best)) {
                break;
            } else {
                $granularity = min(count($best), $granularity * 2);
            }
        }

        return $best;
    }

    /**
     * Fine phase: try removing one execution at a time, from the back, until a
     * whole pass removes nothing — the polish that reduces what coarse chunking
     * left behind.
     *
     * @param  list<int>  $best
     * @return list<int>
     */
    private function sweepSingles(array $best): array
    {
        $changed = true;

        while ($changed && $this->replays < $this->budget) {
            $changed = false;

            for ($i = count($best) - 1; $i >= 0 && count($best) > 1; $i--) {
                $candidate = $this->without($best, $i, 1);

                if ($this->probe($candidate)) {
                    $best = $candidate;
                    $changed = true;
                }

                if ($this->replays >= $this->budget) {
                    return $best;
                }
            }
        }

        return $best;
    }

    /**
     * @param  list<int>  $candidate
     */
    private function probe(array $candidate): bool
    {
        $this->replays++;

        return ($this->reproduces)($candidate);
    }

    /**
     * The subsequence with `$length` positions removed starting at `$start`.
     *
     * @param  list<int>  $positions
     * @return list<int>
     */
    private function without(array $positions, int $start, int $length): array
    {
        return [
            ...array_slice($positions, 0, $start),
            ...array_slice($positions, $start + $length),
        ];
    }
}
