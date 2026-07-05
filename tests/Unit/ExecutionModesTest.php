<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use ArrayObject;
use Closure;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
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
