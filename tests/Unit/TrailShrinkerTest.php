<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Runabout\TrailShrinker;

/**
 * The delta-debugging loop in isolation, driven by synthetic probes with a
 * known minimal — no app, no database, so the algorithm's behaviour is pinned
 * exactly and fast.
 */
final class TrailShrinkerTest extends TestCase
{
    public function test_it_reduces_to_the_positions_the_failure_actually_needs(): void
    {
        // The "bug" needs positions 2 and 5 present; everything else is padding.
        $reproduces = fn (array $positions): bool => in_array(2, $positions, true) && in_array(5, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 9));

        $this->assertSame([2, 5], $result['positions']);
    }

    public function test_it_keeps_a_trail_that_cannot_be_reduced(): void
    {
        // Only the full ten-position trail reproduces: nothing is removable.
        $reproduces = fn (array $positions): bool => count($positions) === 10;

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 9));

        $this->assertCount(10, $result['positions']);
    }

    public function test_it_reduces_to_a_single_execution_when_any_one_reproduces(): void
    {
        $result = (new TrailShrinker(fn (array $positions): bool => $positions !== []))->shrink(range(0, 19));

        $this->assertCount(1, $result['positions']);
    }

    public function test_it_stops_at_the_replay_budget(): void
    {
        $probes = 0;

        $reproduces = function (array $positions) use (&$probes): bool {
            $probes++;

            return in_array(7, $positions, true);
        };

        $result = (new TrailShrinker($reproduces, budget: 5))->shrink(range(0, 99));

        $this->assertLessThanOrEqual(5, $probes, 'The shrinker must not exceed its replay budget.');
        $this->assertContains(7, $result['positions'], 'The best trail found must still reproduce.');
    }

    public function test_it_is_deterministic(): void
    {
        $reproduces = fn (array $positions): bool => in_array(1, $positions, true) && in_array(8, $positions, true);

        $this->assertSame(
            (new TrailShrinker($reproduces))->shrink(range(0, 12))['positions'],
            (new TrailShrinker($reproduces))->shrink(range(0, 12))['positions'],
        );
    }
}
