<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;
use Vusys\Runabout\TrailToken;

/**
 * Seed schema v2: order decisions come from a per-trail picker stream, and
 * each execution's data draws come from a stream keyed by (seed, instance,
 * step, run index) — never by position. These tests pin the property the
 * shrinker depends on: a surviving execution reproduces its draws verbatim
 * however the trail around it is rearranged or thinned.
 */
final class SeedSchemaTest extends TestCase
{
    public function test_a_steps_nth_run_draws_the_same_values_regardless_of_what_ran_between(): void
    {
        $seed = 42;

        $withGaps = $this->drawsInOrder($seed, [['A', 1], ['B', 1], ['A', 2], ['C', 1], ['A', 3]]);
        $backToBack = $this->drawsInOrder($seed, [['A', 1], ['A', 2], ['A', 3]]);

        // A's runs 1-3 draw identical values in both trails even though the
        // first interleaves B and C between them.
        $this->assertSame($withGaps, $backToBack);
        $this->assertCount(3, $backToBack);
    }

    public function test_removing_an_earlier_run_leaves_the_surviving_runs_draws_untouched(): void
    {
        $seed = 42;

        $full = $this->drawsInOrder($seed, [['A', 1], ['A', 2], ['A', 3]]);
        $withoutRunTwo = $this->drawsInOrder($seed, [['A', 1], ['A', 3]]);

        // Dropping run 2 (as a shrinker would) does not shift run 1 or run 3:
        // the run index is the stream key, not a positional recount, so run 3
        // keeps its draw even though it now runs second.
        $this->assertSame($full[0], $withoutRunTwo[0]);
        $this->assertSame($full[2], $withoutRunTwo[1]);
    }

    public function test_the_same_seed_reproduces_the_same_draws_run_to_run(): void
    {
        $tokens = [['A', 1], ['A', 2]];

        $this->assertSame(
            $this->drawsInOrder(7, $tokens),
            $this->drawsInOrder(7, $tokens),
        );
    }

    /**
     * Replay the given token order and return step A's drawn values in
     * execution order (position i is the ith A execution in $order).
     *
     * @param  list<array{0: string, 1: int}>  $order
     * @return list<int>
     */
    private function drawsInOrder(int $seed, array $order): array
    {
        /** @var ArrayObject<int, int> $draws */
        $draws = new ArrayObject;

        $journey = new class($draws) extends Journey
        {
            /** @param ArrayObject<int, int> $draws */
            public function __construct(private readonly ArrayObject $draws) {}

            public function steps(): array
            {
                $draws = $this->draws;

                return [
                    Step::make('A')->repeatable(max: 10)->act(function (Context $ctx) use ($draws): void {
                        $draws->append($ctx->randomInt(1, 1_000_000));
                    }),
                    Step::make('B')->repeatable(max: 10),
                    Step::make('C')->repeatable(max: 10),
                ];
            }
        };

        $tokens = array_map(fn (array $slot): TrailToken => new TrailToken(null, $slot[0], $slot[1]), $order);

        (new JourneyRunner)->runTokens([$journey], $seed, $tokens);

        return array_values($draws->getArrayCopy());
    }
}
