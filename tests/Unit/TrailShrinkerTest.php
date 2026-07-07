<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Runabout\TrailShrinker;

/**
 * The delta-debugging loop in isolation, driven by synthetic probes with a
 * known minimal — no app, no database, so the algorithm's behaviour is pinned
 * exactly and fast.
 *
 * Several tests below pin the exact `replays` count in addition to the final
 * `positions`, on purpose: the coarse chunk-removal phase (removeChunks) and
 * the backward single-element polish phase (sweepSingles) share a single
 * replay budget, and many of the interesting bugs in this class only change
 * *how many* probes are spent, not the mere reachability of the correct
 * final answer.
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

    public function test_it_stops_at_exactly_the_default_budget_of_two_hundred_replays(): void
    {
        // A probe that never reproduces forces the shrinker to spend its
        // entire budget: with 300 positions there is always another chunk or
        // single position left to try, so it runs until the budget itself
        // — not the trail — is exhausted. Pins the *default* budget value
        // exactly (not 199, not 201): the constructor argument is omitted.
        $shrinker = new TrailShrinker(fn (array $positions): bool => false);

        $result = $shrinker->shrink(range(0, 299));

        $this->assertSame(200, $result['replays']);
        $this->assertSame(range(0, 299), $result['positions']);
    }

    public function test_it_stops_chunking_once_only_two_positions_remain(): void
    {
        // Exercises the exact >= 2 lower bound on coarse chunking. Only
        // removing position 10 (the front element) reproduces; removing 20
        // does not. removeChunks' forward chunk order tries the front first
        // and succeeds in a single probe, after which count(best) drops to 1
        // and coarse removal stops; sweepSingles then has nothing left to do.
        $reproduces = fn (array $positions): bool => $positions === [20];

        $result = (new TrailShrinker($reproduces))->shrink([10, 20]);

        $this->assertSame([20], $result['positions']);
        $this->assertSame(1, $result['replays']);
    }

    public function test_a_zero_budget_allows_no_probing_at_all(): void
    {
        // With budget: 0, neither removeChunks nor sweepSingles may call the
        // probe even once: the trail comes back completely unchanged and no
        // replays are recorded.
        $reproduces = fn (array $positions): bool => false;

        $result = (new TrailShrinker($reproduces, budget: 0))->shrink([0, 1, 2, 3, 4]);

        $this->assertSame(0, $result['replays']);
        $this->assertSame([0, 1, 2, 3, 4], $result['positions']);
    }

    public function test_coarse_chunk_removal_contributes_within_a_tight_budget(): void
    {
        // Budget 3 is exactly enough for two failed halves plus one
        // successful quarter-chunk removal in removeChunks; the outer loop's
        // budget check then stops everything before sweepSingles gets a
        // chance to run at all. If coarse removal did not run (or ran
        // differently), this exact five-position, three-replay result would
        // not appear.
        $reproduces = fn (array $positions): bool => in_array(2, $positions, true) && in_array(5, $positions, true);

        $result = (new TrailShrinker($reproduces, budget: 3))->shrink(range(0, 5));

        $this->assertSame([1, 2, 3, 4, 5], $result['positions']);
        $this->assertSame(3, $result['replays']);
    }

    public function test_it_shrinks_to_the_last_position_when_only_it_is_needed(): void
    {
        $reproduces = fn (array $positions): bool => in_array(4, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 4));

        $this->assertSame([4], $result['positions']);
        $this->assertSame(3, $result['replays']);
    }

    public function test_it_keeps_two_widely_separated_needed_positions(): void
    {
        $reproduces = fn (array $positions): bool => in_array(1, $positions, true) && in_array(6, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 7));

        $this->assertSame([1, 6], $result['positions']);
        $this->assertSame(16, $result['replays']);
    }

    public function test_it_keeps_the_first_and_last_positions_when_both_are_needed(): void
    {
        $reproduces = fn (array $positions): bool => in_array(0, $positions, true) && in_array(6, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 6));

        $this->assertSame([0, 6], $result['positions']);
        $this->assertSame(14, $result['replays']);
    }

    public function test_it_shrinks_to_a_single_middle_position(): void
    {
        $reproduces = fn (array $positions): bool => in_array(3, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 7));

        $this->assertSame([3], $result['positions']);
        $this->assertSame(4, $result['replays']);
    }

    public function test_a_tight_budget_of_two_still_finds_the_needed_position(): void
    {
        $reproduces = fn (array $positions): bool => in_array(1, $positions, true);

        $result = (new TrailShrinker($reproduces, budget: 2))->shrink([0, 1, 2]);

        $this->assertSame([1, 2], $result['positions']);
        $this->assertSame(2, $result['replays']);
    }

    public function test_it_shrinks_to_the_minimum_count_satisfying_the_predicate(): void
    {
        $reproduces = fn (array $positions): bool => count($positions) >= 3;

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 9));

        $this->assertSame([7, 8, 9], $result['positions']);
        $this->assertSame(10, $result['replays']);
    }

    public function test_a_single_position_trail_needs_no_probing(): void
    {
        $result = (new TrailShrinker(fn (array $positions): bool => true))->shrink([42]);

        $this->assertSame([42], $result['positions']);
        $this->assertSame(0, $result['replays']);
    }

    public function test_it_keeps_exactly_two_positions_when_both_are_needed(): void
    {
        $reproduces = fn (array $positions): bool => count($positions) === 2;

        $result = (new TrailShrinker($reproduces))->shrink([1, 2]);

        $this->assertSame([1, 2], $result['positions']);
        $this->assertSame(4, $result['replays']);
    }

    public function test_it_shrinks_a_larger_trail_to_two_needed_positions(): void
    {
        $reproduces = fn (array $positions): bool => in_array(3, $positions, true) && in_array(11, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 15));

        $this->assertSame([3, 11], $result['positions']);
        $this->assertSame(21, $result['replays']);
    }

    public function test_a_budget_of_six_yields_a_partially_reduced_result(): void
    {
        $reproduces = fn (array $positions): bool => in_array(2, $positions, true) && in_array(5, $positions, true);

        $result = (new TrailShrinker($reproduces, budget: 6))->shrink(range(0, 6));

        $this->assertSame([1, 2, 5, 6], $result['positions']);
        $this->assertSame(6, $result['replays']);
    }

    public function test_sweep_singles_removes_one_position_at_a_time_not_two(): void
    {
        // A parity trap: reproducing requires an *even* position count plus
        // the first and last positions. Removing any single position always
        // breaks parity, so once coarse removal (removeChunks) has tried
        // every remaining single-position removal and found all of them
        // failing, it gives up with four positions left — and that failure
        // is proof positive that no single-position removal from this exact
        // set can succeed either, so the correct sweepSingles phase is a
        // no-op too. A sweep that removed *two* positions at once (i and
        // i + 1) instead of one would still satisfy the even-count
        // requirement and could — incorrectly — carry on shrinking past the
        // point the algorithm is allowed to stop at.
        $reproduces = fn (array $positions): bool => count($positions) % 2 === 0
            && in_array(0, $positions, true)
            && in_array(5, $positions, true);

        $result = (new TrailShrinker($reproduces))->shrink(range(0, 5));

        $this->assertSame([0, 3, 4, 5], $result['positions']);
        $this->assertSame(15, $result['replays']);
    }

    public function test_the_sweep_phase_stops_at_exactly_the_replay_budget(): void
    {
        // Budget 18 lands squarely inside the window where coarse removal
        // (removeChunks) has already exhausted its own possibilities
        // (14 replays for this never-reproduces probe over 8 positions) and
        // hands off to sweepSingles with exactly 4 replays of budget left.
        // sweepSingles' own internal budget check must stop it at replay 18
        // exactly — one probe later or earlier changes this count.
        $reproduces = fn (array $positions): bool => false;

        $result = (new TrailShrinker($reproduces, budget: 18))->shrink(range(0, 7));

        $this->assertSame(range(0, 7), $result['positions']);
        $this->assertSame(18, $result['replays']);
    }
}
