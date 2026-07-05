<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use ArrayObject;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;

final class JourneyRunnerTest extends TestCase
{
    public function test_the_same_seed_produces_the_same_trail(): void
    {
        $runner = new JourneyRunner;

        $first = $runner->run($this->independentJourney(), seed: 12345)->steps();
        $second = $runner->run($this->independentJourney(), seed: 12345)->steps();

        $this->assertSame($first, $second);
    }

    public function test_different_seeds_explore_different_orders(): void
    {
        $runner = new JourneyRunner;

        $trails = [];
        for ($seed = 1; $seed <= 10; $seed++) {
            $trails[] = implode(',', $runner->run($this->independentJourney(), $seed)->steps());
        }

        $this->assertGreaterThan(1, count(array_unique($trails)));
    }

    public function test_after_constraints_hold_in_every_shuffle(): void
    {
        $runner = new JourneyRunner;

        $journey = $this->journey([
            Step::make('first'),
            Step::make('second')->after('first'),
            Step::make('third')->after('second'),
            Step::make('anytime'),
        ]);

        for ($seed = 1; $seed <= 25; $seed++) {
            $steps = $runner->run($journey, $seed)->steps();

            $this->assertLessThan(array_search('second', $steps, true), array_search('first', $steps, true));
            $this->assertLessThan(array_search('third', $steps, true), array_search('second', $steps, true));
        }
    }

    public function test_canonical_mode_runs_steps_in_declared_order(): void
    {
        $runner = new JourneyRunner;

        $trail = $runner->run($this->independentJourney(), seed: 1, shuffle: false);

        $this->assertSame(['a', 'b', 'c', 'd'], $trail->steps());
    }

    public function test_an_unsatisfiable_precondition_is_reported_as_a_deadlock(): void
    {
        $journey = $this->journey([
            Step::make('possible'),
            Step::make('impossible')->when(fn (): bool => false),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected a deadlock failure.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Deadlock', $e->getMessage());
            $this->assertStringContainsString('"impossible"', $e->getMessage());
        }
    }

    public function test_duplicate_step_names_are_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);

        (new JourneyRunner)->run($this->journey([Step::make('twin'), Step::make('twin')]), seed: 1);
    }

    public function test_unknown_after_dependencies_are_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);

        (new JourneyRunner)->run($this->journey([Step::make('orphan')->after('missing')]), seed: 1);
    }

    public function test_invariants_run_after_every_step(): void
    {
        $checks = 0;

        $journey = $this->journey(
            [Step::make('a'), Step::make('b'), Step::make('c')],
            [Invariant::make('count checks', function () use (&$checks): void {
                $checks++;
            })],
        );

        $trail = (new JourneyRunner)->run($journey, seed: 7);

        $this->assertSame(count($trail->steps()), $checks);
    }

    public function test_a_violated_invariant_names_itself_and_the_step(): void
    {
        $journey = $this->journey(
            [Step::make('harmless')],
            [Invariant::make('always broken', fn () => Assert::fail('nope'))],
        );

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected the invariant to fail the journey.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Invariant "always broken" violated after step "harmless"', $e->getMessage());
            $this->assertStringContainsString('RUNABOUT_SEED=1', $e->getMessage());
        }
    }

    public function test_teardowns_run_in_reverse_order_and_also_on_failure(): void
    {
        $log = [];

        $journey = $this->journey([
            Step::make('open a')
                ->act(function () use (&$log): void {
                    $log[] = 'open a';
                })
                ->teardown(function () use (&$log): void {
                    $log[] = 'close a';
                }),
            Step::make('open b')
                ->after('open a')
                ->act(function () use (&$log): void {
                    $log[] = 'open b';
                })
                ->teardown(function () use (&$log): void {
                    $log[] = 'close b';
                }),
            Step::make('explode')
                ->after('open b')
                ->act(fn () => Assert::fail('boom')),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1, shuffle: false);
            $this->fail('Expected the journey to fail.');
        } catch (JourneyFailedException) {
            $this->assertSame(['open a', 'open b', 'close b', 'close a'], $log);
        }
    }

    public function test_a_repeatable_step_can_tell_whether_it_ran_before(): void
    {
        /** @var ArrayObject<int, bool> $observed */
        $observed = new ArrayObject;

        $journey = $this->journey([
            Step::make('slow burner'),
            Step::make('again and again')
                ->repeatable()
                ->assert(function (Context $ctx) use ($observed): void {
                    $observed[] = $ctx->ranBefore('again and again');
                }),
        ]);

        // Find a seed whose trail repeats the repeatable step; seeds are deterministic, so this is stable.
        for ($seed = 1; $seed <= 50; $seed++) {
            $observed->exchangeArray([]);
            $trail = (new JourneyRunner)->run($journey, $seed);
            $runs = array_values($observed->getArrayCopy());

            if ($runs !== [] && count($trail->steps()) > 2) {
                $this->assertFalse($runs[0], 'First execution must see ranBefore() === false.');
                $this->assertTrue(end($runs), 'Repeat executions must see ranBefore() === true.');

                return;
            }
        }

        $this->fail('No seed in 1..50 repeated the repeatable step.');
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    private function journey(array $steps, array $invariants = []): Journey
    {
        return new class($steps, $invariants) extends Journey
        {
            /**
             * @param  list<Step>  $steps
             * @param  list<Invariant>  $invariants
             */
            public function __construct(
                private readonly array $steps,
                private readonly array $invariants,
            ) {}

            public function steps(): array
            {
                return $this->steps;
            }

            public function invariants(): array
            {
                return $this->invariants;
            }
        };
    }

    /** Four unordered steps, one of them repeatable, so shuffles genuinely vary. */
    private function independentJourney(): Journey
    {
        return $this->journey([
            Step::make('a'),
            Step::make('b'),
            Step::make('c')->repeatable(max: 3),
            Step::make('d'),
        ]);
    }
}
