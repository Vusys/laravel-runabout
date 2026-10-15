<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use ArrayObject;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Execution\JourneyRunner;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Trail;

final class ExecutionModesTest extends TestCase
{
    public function test_weight_must_be_at_least_one(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('needs a weight of at least 1');

        Step::make('feather')->weight(0);
    }

    public function test_heavier_steps_are_picked_more_often(): void
    {
        $this->assertGreaterThan(
            $this->repeatableRuns(weight: 1),
            $this->repeatableRuns(weight: 8),
        );
    }

    public function test_repeat_heavy_mode_reruns_repeatable_steps_more_often(): void
    {
        $this->assertGreaterThan(
            $this->repeatableRuns(weight: 1, repeatBias: 1),
            $this->repeatableRuns(weight: 1, repeatBias: 5),
        );
    }

    public function test_exhaustive_mode_runs_every_valid_ordering(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;

        $journey = $this->journey([
            Step::make('a'),
            Step::make('b')
                ->after('a')
                ->act(function () use ($log): void {
                    $log[] = 'b';
                }),
            Step::make('c'),
        ]);

        $this->pending($journey)->exhaustive()->run();

        // Of the 6 orderings of three steps, exactly 3 satisfy "b after a"
        // (abc, acb, cab) — and b executes only in those complete orders.
        $this->assertCount(3, $log);
    }

    public function test_exhaustive_mode_refuses_journeys_beyond_the_ordering_limit(): void
    {
        $journey = $this->journey(array_map(
            fn (int $i): Step => Step::make('step '.$i),
            range(1, 7), // 7! = 5040 orderings, beyond the default limit of 720
        ));

        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Exhaustive mode would run more than 720 orderings');

        $this->pending($journey)->exhaustive()->run();
    }

    public function test_on_trail_observes_every_completed_trail(): void
    {
        $trails = [];

        $journey = $this->journey([Step::make('a'), Step::make('b'), Step::make('c')]);

        $this->pending($journey)
            ->shuffles(5)
            ->onTrail(function (Trail $trail) use (&$trails): void {
                $trails[] = $trail;
            })
            ->run();

        $this->assertCount(6, $trails);
        $this->assertSame('canonical', $trails[0]->mode());

        foreach (array_slice($trails, 1) as $trail) {
            $this->assertSame('shuffled', $trail->mode());
            $this->assertCount(3, $trail->steps());
        }
    }

    public function test_on_trail_observes_each_valid_exhaustive_ordering(): void
    {
        $trails = [];

        $journey = $this->journey([
            Step::make('a'),
            Step::make('b')->after('a'),
            Step::make('c'),
        ]);

        $this->pending($journey)
            ->exhaustive()
            ->onTrail(function (Trail $trail) use (&$trails): void {
                $trails[] = $trail;
            })
            ->run();

        $this->assertCount(3, $trails);

        foreach ($trails as $trail) {
            $this->assertSame('exhaustive', $trail->mode());
        }
    }

    public function test_on_trail_does_not_observe_a_failed_trail(): void
    {
        $observed = 0;

        $journey = $this->journey([
            Step::make('doomed')->act(function (): never {
                throw new RuntimeException('boom');
            }),
        ]);

        try {
            $this->pending($journey)
                ->onTrail(function () use (&$observed): void {
                    $observed++;
                })
                ->run();
            $this->fail('Expected the journey to fail.');
        } catch (JourneyFailedException) {
            $this->assertSame(0, $observed);
        }
    }

    public function test_seed_replays_exactly_one_shuffled_trail(): void
    {
        /** @var list<Trail> $trails */
        $trails = [];

        $this->pending($this->journey([Step::make('a'), Step::make('b')]))
            ->seed(123)
            ->onTrail(function (Trail $trail) use (&$trails): void {
                $trails[] = $trail;
            })
            ->run();

        $this->assertCount(1, $trails);
        $this->assertSame('shuffled', $trails[0]->mode());
        $this->assertSame(123, $trails[0]->seed());
    }

    public function test_repeat_heavy_trails_carry_their_mode(): void
    {
        /** @var list<string> $modes */
        $modes = [];

        $this->pending($this->journey([Step::make('a'), Step::make('b')->repeatable(max: 5)]))
            ->repeatHeavy()
            ->shuffles(2)
            ->onTrail(function (Trail $trail) use (&$modes): void {
                $modes[] = $trail->mode();
            })
            ->run();

        $this->assertSame(['canonical', 'repeat-heavy', 'repeat-heavy'], $modes);
    }

    public function test_at_least_one_journey_is_required(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('At least one journey is required');

        new PendingJourney([], fn (Closure $trail) => $trail());
    }

    public function test_exhaustive_mode_refuses_interleaved_journeys(): void
    {
        $pending = new PendingJourney(
            [$this->journey([Step::make('a')]), $this->journey([Step::make('b')])],
            fn (Closure $trail) => $trail(),
        );

        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Exhaustive mode is not available for interleaved journeys');

        $pending->exhaustive()->run();
    }

    public function test_randomize_env_explores_fresh_seeds_each_run(): void
    {
        putenv('RUNABOUT_RANDOMIZE=1');

        try {
            $this->assertNotSame($this->trailLog(), $this->trailLog(), 'Two RUNABOUT_RANDOMIZE runs must explore different orderings.');
        } finally {
            putenv('RUNABOUT_RANDOMIZE');
        }
    }

    public function test_runs_are_deterministic_without_the_randomize_env(): void
    {
        $this->assertSame($this->trailLog(), $this->trailLog());
    }

    /** @return list<string> The concatenated step order of canonical + 10 shuffles. */
    private function trailLog(): array
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;

        $append = fn (string $name): Closure => function () use ($log, $name): void {
            $log[] = $name;
        };

        $journey = $this->journey([
            Step::make('a')->act($append('a')),
            Step::make('b')->act($append('b')),
            Step::make('c')->act($append('c')),
            Step::make('d')->act($append('d')),
        ]);

        $this->pending($journey)->shuffles(10)->run();

        return array_values($log->getArrayCopy());
    }

    /** Total executions of a repeatable step across seeds 1-15. */
    public function test_min_runs_cannot_be_negative(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('needs a minimum of at least 0 runs, got -1');

        Step::make('walk')->repeatable(max: 5, min: -1);
    }

    public function test_optional_and_min_zero_both_make_a_step_optional(): void
    {
        $this->assertSame(0, Step::make('a')->optional()->minRuns());
        $this->assertSame(0, Step::make('b')->repeatable(max: 3, min: 0)->minRuns());
        $this->assertSame(0, Step::make('c')->optional()->repeatable(max: 3)->minRuns(), 'repeatable() keeps an earlier optional().');
        $this->assertSame(1, Step::make('d')->repeatable(max: 3)->minRuns());
    }

    public function test_a_step_closed_off_by_another_branch_does_not_deadlock_when_optional(): void
    {
        $closedOff = function (bool $optional): Journey {
            $branch = Step::make('branch')->when(fn ($ctx): bool => ! $ctx->has('closed'));

            return $this->journey([
                Step::make('close')->act(fn ($ctx): mixed => $ctx->remember('closed', true)),
                $optional ? $branch->optional() : $branch,
                Step::make('tail'),
            ]);
        };

        /** @var ArrayObject<int, string> $trails */
        $trails = new ArrayObject;

        // Declared order closes the branch before reaching it; the skip must
        // carry on to the steps after it, not end the trail there.
        $this->pending($closedOff(true))->shuffles(20)->onTrail(function (Trail $trail) use ($trails): void {
            $trails[] = implode(',', $trail->steps());
        })->run();

        $this->assertSame('close,tail', $trails[0]);

        $this->expectException(JourneyFailedException::class);
        $this->expectExceptionMessage('Step "branch" is not enabled when reached in declared order');
        $this->pending($closedOff(false))->shuffles(0)->run();
    }

    public function test_skipping_an_optional_step_in_declared_order_does_not_skip_other_instances(): void
    {
        /** @var ArrayObject<int, list<string>> $trails */
        $trails = new ArrayObject;

        $closing = $this->journey([
            Step::make('close')->act(fn ($ctx): mixed => $ctx->remember('closed', true)),
            Step::make('branch')->when(fn ($ctx): bool => ! $ctx->has('closed'))->optional(),
        ]);
        $other = $this->journey([Step::make('first'), Step::make('second')]);

        (new PendingJourney([$closing, $other], fn (Closure $trail) => $trail()))
            ->shuffles(0)
            ->onTrail(function (Trail $trail) use ($trails): void {
                $trails[] = $trail->steps();
            })
            ->run();

        // Round robin by position: A's skipped "branch" shares position 1
        // with B's "second", which must still run.
        $this->assertSame(['A: close', 'B: first', 'B: second'], $trails[0]);
    }

    public function test_min_length_keeps_shuffled_trails_going_past_their_minimums(): void
    {
        $trails = function (?int $minLength, ?int $optionalMax): array {
            /** @var ArrayObject<int, list<string>> $trails */
            $trails = new ArrayObject;
            $pending = $this->pending($this->journey([
                Step::make('required'),
                Step::make('optional')->repeatable(max: $optionalMax, min: 0),
            ]))->shuffles(15)->onTrail(function (Trail $trail) use ($trails): void {
                if ($trail->isShuffled()) {
                    $trails[] = $trail->steps();
                }
            });

            if ($minLength !== null) {
                $pending->minLength($minLength);
            }

            $pending->run();

            return $trails->getArrayCopy();
        };
        $lengths = fn (array $trails): array => array_values(array_unique(array_map(count(...), $trails)));

        foreach ($trails(null, null) as $trail) {
            $this->assertSame('required', end($trail), 'Without minLength a trail stops as soon as "required" has run.');
        }

        $this->assertSame([10], $lengths($trails(10, null)));
        $this->assertSame([3], $lengths($trails(10, 2)), 'A trail with nothing left eligible ends short of minLength instead of failing.');
    }

    public function test_exhaustive_mode_runs_orderings_that_skip_a_closed_off_optional_step(): void
    {
        /** @var ArrayObject<int, string> $orders */
        $orders = new ArrayObject;

        $journey = $this->journey([
            Step::make('close')->act(fn ($ctx): mixed => $ctx->remember('closed', true)),
            Step::make('branch')->when(fn ($ctx): bool => ! $ctx->has('closed'))->optional(),
            Step::make('tail'),
        ]);

        $this->pending($journey)->exhaustive()->onTrail(function (Trail $trail) use ($orders): void {
            $orders[] = implode(',', $trail->steps());
        })->run();

        // Every permutation is viable; a closed-off "branch" is skipped and
        // the steps after it in that ordering still run.
        $this->assertSame(
            ['close,tail', 'close,tail', 'branch,close,tail', 'branch,tail,close', 'tail,close', 'tail,branch,close'],
            $orders->getArrayCopy(),
        );
    }

    public function test_min_runs_cannot_exceed_max(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('has min 6 greater than max 5');

        Step::make('walk')->repeatable(max: 5, min: 6);
    }

    public function test_a_lone_repeatable_step_with_a_minimum_drives_a_multi_step_walk(): void
    {
        // A single always-enabled step reaches its minimum every trail — the
        // shape a state-machine random walk needs, which a plain repeatable
        // step (min 1) cannot express (it would run exactly once).
        $journey = $this->journey([
            Step::make('advance')->repeatable(max: 20, min: 8),
        ]);

        for ($seed = 1; $seed <= 10; $seed++) {
            $trail = (new JourneyRunner)->run($journey, $seed, shuffle: true);
            $runs = count(array_filter($trail->steps(), fn (string $step): bool => $step === 'advance'));

            $this->assertGreaterThanOrEqual(8, $runs);
            $this->assertLessThanOrEqual(20, $runs);
        }
    }

    private function repeatableRuns(int $weight, int $repeatBias = 1): int
    {
        $journey = $this->journey([
            Step::make('again')->repeatable(max: 30)->weight($weight),
            Step::make('one'),
            Step::make('two'),
            Step::make('three'),
        ]);

        $total = 0;

        for ($seed = 1; $seed <= 15; $seed++) {
            $trail = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: $repeatBias);
            $total += count(array_filter($trail->steps(), fn (string $step): bool => $step === 'again'));
        }

        return $total;
    }

    /** @param list<Step> $steps */
    private function journey(array $steps): Journey
    {
        return new class($steps) extends Journey
        {
            /** @param list<Step> $steps */
            public function __construct(private readonly array $steps) {}

            public function steps(): array
            {
                return $this->steps;
            }

            public function invariants(): array
            {
                return [];
            }
        };
    }

    private function pending(Journey $journey): PendingJourney
    {
        return new PendingJourney($journey, fn (Closure $trail) => $trail());
    }
}
