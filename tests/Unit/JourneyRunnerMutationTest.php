<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;
use Vusys\Runabout\TrailToken;

/**
 * Targeted kills for JourneyRunner mutants that survived the existing suite:
 * default-parameter values, run-index arithmetic, the runaway-trail tick
 * budget, teardown/baseline/execution stream derivation (the crc32 sprintf
 * keys), instance-label routing in runTokens(), and the try/finally around
 * executeStep()'s draw bookkeeping.
 *
 * Several tests replicate the runner's private stream-derivation formulas
 * (executionStream/teardownStream/baselineStream/pickerStream) to assert the
 * exact value a mutated formula would fail to reproduce — the formulas
 * themselves are documented in JourneyRunner's docblocks as part of the seed
 * schema, so pinning them here is intentional, not incidental coupling.
 */
final class JourneyRunnerMutationTest extends TestCase
{
    public function test_run_uses_shuffled_mode_by_default_not_repeat_heavy(): void
    {
        $journey = $this->journey([Step::make('a'), Step::make('b')]);

        $trail = (new JourneyRunner)->run($journey, seed: 1);

        $this->assertSame('shuffled', $trail->mode());
    }

    public function test_run_interleaved_defaults_to_shuffled_mode(): void
    {
        $journey = $this->journey([Step::make('a'), Step::make('b')]);

        $trail = (new JourneyRunner)->runInterleaved([$journey], seed: 1);

        $this->assertSame('shuffled', $trail->mode());
    }

    public function test_run_order_records_run_index_one_for_a_first_execution(): void
    {
        $journey = $this->journey([Step::make('only')]);

        $trail = (new JourneyRunner)->runOrder($journey, [0], seed: 1);

        $this->assertSame(1, $trail->tokens()[0]->run);
    }

    public function test_run_tokens_routes_each_token_to_its_labelled_instance(): void
    {
        $ran = [];

        $journeyA = $this->journey([
            Step::make('a')->act(function () use (&$ran): void {
                $ran[] = 'A';
            }),
        ]);
        $journeyB = $this->journey([
            Step::make('b')->act(function () use (&$ran): void {
                $ran[] = 'B';
            }),
        ]);

        $trail = (new JourneyRunner)->runTokens(
            [$journeyA, $journeyB],
            seed: 1,
            tokens: [new TrailToken('B', 'b', 1)],
        );

        $this->assertSame(['B'], $ran);
        $this->assertSame(['B: b'], $trail->steps());
    }

    public function test_the_placeholder_stream_before_any_execution_is_keyed_by_run_zero(): void
    {
        $drawn = null;
        $seed = 5150;

        $journey = $this->journey([
            Step::make('go')->when(function (Context $ctx) use (&$drawn): bool {
                $drawn = $ctx->randomInt(0, 1_000_000);

                return true;
            }),
        ]);

        (new JourneyRunner)->run($journey, $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|%s|%s|%d', $seed, '', '__init__', 0)))))->getInt(0, 1_000_000);

        $this->assertSame($expected, $drawn);
    }

    public function test_teardowns_draw_from_the_dedicated_teardown_stream_not_a_leftover_execution_stream(): void
    {
        $drawnTeardown = null;
        $seed = 909;

        $journey = $this->journey([
            Step::make('only')->teardown(function (Context $ctx) use (&$drawnTeardown): void {
                $drawnTeardown = $ctx->randomInt(0, 999_999);
            }),
        ]);

        (new JourneyRunner)->run($journey, $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|__teardown__|%s', $seed, '')))))->getInt(0, 999_999);

        $this->assertSame($expected, $drawnTeardown);
    }

    public function test_canonical_mode_records_run_index_one_for_a_first_execution(): void
    {
        $journey = $this->journey([Step::make('only')]);

        $trail = (new JourneyRunner)->run($journey, seed: 1, shuffle: false);

        $this->assertSame(1, $trail->tokens()[0]->run);
    }

    public function test_shuffled_mode_records_run_index_one_for_a_first_execution(): void
    {
        $journey = $this->journey([Step::make('only')]);

        $trail = (new JourneyRunner)->run($journey, seed: 1, shuffle: true);

        $this->assertSame(1, $trail->tokens()[0]->run);
    }

    public function test_the_runaway_cap_allows_exactly_the_hundred_execution_floor(): void
    {
        $executions = 0;

        // minTotal = spin(1) + never(1) = 2, so 2 * TICK_MULTIPLIER(25) = 50 is
        // below the 100 floor: the floor, not the multiplier, decides the cap.
        $journey = $this->journey([
            Step::make('spin')->repeatable()->act(function () use (&$executions): void {
                $executions++;
            }),
            Step::make('never')->when(fn (): bool => false),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected a runaway-trail failure.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Runaway trail: 100 steps executed', $e->getMessage());
            $this->assertSame(100, $executions);
        }
    }

    public function test_the_runaway_cap_scales_with_the_declared_minimum_runs(): void
    {
        $executions = 0;

        // minTotal = spin(1) + grind(10) + never(1) = 12, so 12 * 25 = 300 —
        // well above the 100 floor, so this pins the multiplication itself.
        $journey = $this->journey([
            Step::make('spin')->repeatable()->act(function () use (&$executions): void {
                $executions++;
            }),
            Step::make('grind')->repeatable(max: 50, min: 10)->act(function () use (&$executions): void {
                $executions++;
            }),
            Step::make('never')->when(fn (): bool => false),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected a runaway-trail failure.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Runaway trail: 300 steps executed', $e->getMessage());
            $this->assertSame(300, $executions);
        }
    }

    public function test_a_steps_draws_are_recorded_even_when_it_throws(): void
    {
        $journey = $this->journey([
            Step::make('doomed')->act(function (Context $ctx): never {
                $ctx->randomInt(0, 10);

                throw new RuntimeException('boom');
            }),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1, shuffle: false);
            $this->fail('Expected the journey to fail.');
        } catch (JourneyFailedException $e) {
            $this->assertNotSame([], $e->trail()->drawsAt(0));
        }
    }

    public function test_an_invariant_owned_by_a_different_instance_draws_from_the_acting_executions_stream(): void
    {
        $capturedDuringA = null;
        $captured = false;
        $seed = 777;

        $journeyA = $this->journey([Step::make('a')]);
        $journeyB = $this->journey(
            [Step::make('b')],
            [Invariant::make('watches', function (Context $ctx) use (&$capturedDuringA, &$captured): void {
                if (! $captured) {
                    $capturedDuringA = $ctx->randomInt(0, 999_999);
                    $captured = true;
                }
            })],
        );

        (new JourneyRunner)->runInterleaved([$journeyA, $journeyB], $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|%s|%s|%d', $seed, 'A', 'a', 1)))))->getInt(0, 999_999);

        $this->assertSame($expected, $capturedDuringA);
    }

    public function test_baseline_checks_run_for_every_instance_not_just_the_first(): void
    {
        $baselineAndStepChecks = 0;

        // A owns no invariants at all, so checkBaseline() must skip straight
        // past it to B — not abandon the whole loop before reaching B.
        $journeyA = $this->journey([Step::make('a')]);
        $journeyB = $this->journey(
            [Step::make('b')],
            [Invariant::make('counts', function () use (&$baselineAndStepChecks): void {
                $baselineAndStepChecks++;
            })->fromStart()],
        );

        (new JourneyRunner)->runInterleaved([$journeyA, $journeyB], seed: 1, shuffle: false);

        // 1 baseline check (before any step) + 1 after A's step + 1 after B's
        // step, since B's invariants are checked after every execution.
        $this->assertSame(3, $baselineAndStepChecks);
    }

    public function test_baseline_invariants_draw_from_the_dedicated_baseline_stream(): void
    {
        $drawnBaseline = null;
        $captured = false;
        $seed = 321;

        // This invariant is also checked again after the step runs (against
        // the execution stream, not the baseline stream) — capture only the
        // first (baseline) check.
        $journey = $this->journey(
            [Step::make('only')],
            [Invariant::make('baseline draw', function (Context $ctx) use (&$drawnBaseline, &$captured): void {
                if (! $captured) {
                    $drawnBaseline = $ctx->randomInt(0, 999_999);
                    $captured = true;
                }
            })->fromStart()],
        );

        (new JourneyRunner)->run($journey, $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|__baseline__|%s', $seed, '')))))->getInt(0, 999_999);

        $this->assertSame($expected, $drawnBaseline);
    }

    public function test_a_deadlock_message_names_every_unmet_step(): void
    {
        $journey = $this->journey([
            Step::make('first blocked')->when(fn (): bool => false),
            Step::make('second blocked')->when(fn (): bool => false),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected a deadlock failure.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('"first blocked"', $e->getMessage());
            $this->assertStringContainsString('"second blocked"', $e->getMessage());
        }
    }

    public function test_the_picker_stream_is_derived_from_seed_then_marker_not_the_reverse(): void
    {
        // Both steps carry equal weight, so a wrongly-ordered concatenation
        // would still predict the right first pick on roughly half of all
        // seeds by chance; requiring every seed in a run to match drives that
        // down to effectively zero.
        $predicted = [];
        $actual = [];

        $journey = $this->journey([Step::make('x'), Step::make('y')]);

        for ($seed = 1; $seed <= 20; $seed++) {
            $picker = new Randomizer(new Mt19937(crc32($seed.'|__picker__')));
            $roll = $picker->getInt(1, 2);
            $predicted[] = $roll === 1 ? 'x' : 'y';

            $trail = (new JourneyRunner)->run($journey, $seed, shuffle: true);
            $actual[] = $trail->steps()[0];
        }

        $this->assertSame($predicted, $actual);
    }

    public function test_an_instances_own_label_keys_its_execution_stream(): void
    {
        $capturedB = null;
        $seed = 111;

        $journeyA = $this->journey([Step::make('a')]);
        $journeyB = $this->journey([
            Step::make('b')->act(function (Context $ctx) use (&$capturedB): void {
                $capturedB = $ctx->randomInt(0, 999_999);
            }),
        ]);

        (new JourneyRunner)->runInterleaved([$journeyA, $journeyB], $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|%s|%s|%d', $seed, 'B', 'b', 1)))))->getInt(0, 999_999);

        $this->assertSame($expected, $capturedB);
    }

    public function test_an_instances_own_label_keys_its_teardown_stream(): void
    {
        $capturedTeardownB = null;
        $seed = 222;

        $journeyA = $this->journey([Step::make('a')]);
        $journeyB = $this->journey([
            Step::make('b')->teardown(function (Context $ctx) use (&$capturedTeardownB): void {
                $capturedTeardownB = $ctx->randomInt(0, 999_999);
            }),
        ]);

        (new JourneyRunner)->runInterleaved([$journeyA, $journeyB], $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|__teardown__|%s', $seed, 'B')))))->getInt(0, 999_999);

        $this->assertSame($expected, $capturedTeardownB);
    }

    public function test_an_instances_own_label_keys_its_baseline_stream(): void
    {
        $capturedBaselineB = null;
        $captured = false;
        $seed = 333;

        // Also checked again after B's own step runs (against the execution
        // stream, not the baseline stream) — capture only the first check.
        $journeyA = $this->journey([Step::make('a')]);
        $journeyB = $this->journey(
            [Step::make('b')],
            [Invariant::make('baseline', function (Context $ctx) use (&$capturedBaselineB, &$captured): void {
                if (! $captured) {
                    $capturedBaselineB = $ctx->randomInt(0, 999_999);
                    $captured = true;
                }
            })->fromStart()],
        );

        (new JourneyRunner)->runInterleaved([$journeyA, $journeyB], $seed, shuffle: false);

        $expected = (new Randomizer(new Mt19937(crc32(sprintf('%d|__baseline__|%s', $seed, 'B')))))->getInt(0, 999_999);

        $this->assertSame($expected, $capturedBaselineB);
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
}
