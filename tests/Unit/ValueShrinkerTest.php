<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Draw;
use Vusys\Runabout\ValueShrinker;

/**
 * The per-value binary search in isolation, driven by synthetic probes with
 * a known minimal — no app, no database, so the algorithm's behaviour is
 * pinned exactly and fast.
 *
 * Several tests pin the exact `replays` count in addition to the final
 * `forced` values, on purpose: this class shares its overall shape with
 * TrailShrinker (deterministic, budget-capped, single shared replay
 * counter), and many of its bugs only change *how many* probes are spent on
 * the way to the same-looking answer, not the answer's reachability.
 */
final class ValueShrinkerTest extends TestCase
{
    public function test_it_reduces_each_draw_toward_its_minimum(): void
    {
        $reproduces = fn (array $forced): bool => $forced[0][0] >= 50;

        $result = (new ValueShrinker($reproduces))->shrink([
            0 => [new Draw(min: 0, max: 100, value: 100)],
        ]);

        $this->assertSame(['forced' => [0 => [0 => 50]], 'replays' => 6], $result);
    }

    public function test_a_draw_already_at_its_minimum_is_returned_unchanged_with_no_probes(): void
    {
        // high === min: the value cannot be reduced any further, and the
        // shrinker must recognise this without spending a single probe.
        $probes = 0;
        $reproduces = function (array $forced) use (&$probes): bool {
            $probes++;

            return true;
        };

        $result = (new ValueShrinker($reproduces))->shrink([
            0 => [new Draw(min: 5, max: 10, value: 5)],
        ]);

        $this->assertSame(['forced' => [0 => [0 => 5]], 'replays' => 0], $result);
        $this->assertSame(0, $probes, 'A draw already at its minimum must not be probed at all.');
    }

    public function test_it_minimises_multiple_positions_of_the_same_token_independently(): void
    {
        $reproduces = fn (array $forced): bool => $forced[0][0] >= 30 && $forced[0][1] >= 5;

        $result = (new ValueShrinker($reproduces))->shrink([
            0 => [
                new Draw(min: 0, max: 100, value: 100),
                new Draw(min: 0, max: 20, value: 20),
            ],
        ]);

        $this->assertSame(['forced' => [0 => [0 => 30, 1 => 5]], 'replays' => 11], $result);
    }

    public function test_it_stops_at_exactly_the_default_budget_of_two_hundred_replays(): void
    {
        // A probe that never reproduces forces every token's binary search
        // to run to completion (low climbs to high without ever finding a
        // lower reproducing value). Thirty tokens each need nine replays to
        // converge over a [0, 1000] domain — 270 total — comfortably more
        // than the budget, so the *default* budget value (not 199, not 201)
        // is what actually stops it, at replay 200 exactly. The constructor
        // argument is omitted on purpose.
        $baseline = [];
        for ($token = 0; $token < 30; $token++) {
            $baseline[$token] = [new Draw(min: 0, max: 1000, value: 1000)];
        }

        $shrinker = new ValueShrinker(fn (array $forced): bool => false);

        $result = $shrinker->shrink($baseline);

        $this->assertSame(200, $result['replays']);
        foreach ($result['forced'] as $forcedValues) {
            $this->assertSame([1000], $forcedValues, 'A draw that never reproduces at a lower value must stay at its original value.');
        }
    }

    public function test_the_replay_budget_is_shared_across_tokens(): void
    {
        // Budget 1 is exhausted entirely inside the first token's binary
        // search (mid = 5 fails to reproduce, consuming the only replay
        // allowed), so the second token is never even attempted and keeps
        // its original recorded value.
        $reproduces = fn (array $forced): bool => false;

        $result = (new ValueShrinker($reproduces, budget: 1))->shrink([
            0 => [new Draw(min: 0, max: 10, value: 10)],
            1 => [new Draw(min: 0, max: 10, value: 10)],
        ]);

        $this->assertSame(['forced' => [0 => [0 => 10], 1 => [0 => 10]], 'replays' => 1], $result);
    }
}
