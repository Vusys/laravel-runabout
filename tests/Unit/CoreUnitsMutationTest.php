<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\TestCase;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Vusys\Runabout\Context;
use Vusys\Runabout\DeferredStack;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyInstance;
use Vusys\Runabout\ScriptedDrawSource;
use Vusys\Runabout\Step;
use Vusys\Runabout\StreamDrawSource;

/**
 * Targeted kills for the small-class mutants that survived the existing
 * suite: JourneyInstance::describe()'s label branch, Step::repeatable()'s
 * default minimum and its min-vs-max boundary, Context's shared deferred
 * stack and the two time-travel methods' deferred clock unwind, and the
 * cursor/opacity boundaries in ScriptedDrawSource and StreamDrawSource.
 */
final class CoreUnitsMutationTest extends TestCase
{
    public function test_describe_returns_the_bare_class_name_when_unlabelled(): void
    {
        $journey = $this->dummyJourney();
        $instance = new JourneyInstance($journey, null, [], [], $this->context());

        $this->assertSame($journey::class, $instance->describe());
    }

    public function test_describe_prefixes_the_label_when_labelled(): void
    {
        $journey = $this->dummyJourney();
        $instance = new JourneyInstance($journey, 'A', [], [], $this->context());

        $this->assertSame('A='.$journey::class, $instance->describe());
    }

    public function test_repeatable_defaults_to_a_minimum_of_one_run(): void
    {
        $step = Step::make('walk')->repeatable();

        $this->assertSame(1, $step->minRuns());
    }

    public function test_repeatable_allows_min_to_equal_max(): void
    {
        $step = Step::make('walk')->repeatable(max: 5, min: 5);

        $this->assertSame(5, $step->minRuns());
    }

    public function test_a_shared_deferred_stack_is_used_by_every_context_it_is_passed_to(): void
    {
        $deferred = new DeferredStack;
        $ran = [];

        $first = new Context(new Randomizer(new Mt19937(1)), null, $deferred);
        $second = new Context(new Randomizer(new Mt19937(2)), null, $deferred);

        $first->defer(function () use (&$ran): void {
            $ran[] = 'first';
        });

        // Both contexts must drain the same underlying stack — this is what
        // lets one instance's context unwind every instance's teardowns.
        $drainedFromSecond = $second->drainDeferred();
        $this->assertCount(1, $drainedFromSecond);

        $drainedFromSecond[0]();
        $this->assertSame(['first'], $ran);
    }

    public function test_travel_to_registers_a_deferred_clock_unwind(): void
    {
        $ctx = $this->context();

        try {
            $ctx->travelTo('2030-01-01 00:00:00');

            $deferred = $ctx->drainDeferred();
            $this->assertCount(1, $deferred);

            $deferred[0]();
            $this->assertFalse(Date::hasTestNow());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_travel_registers_a_deferred_clock_unwind(): void
    {
        $ctx = $this->context();

        try {
            $ctx->travel('+1 day');

            $deferred = $ctx->drainDeferred();
            $this->assertCount(1, $deferred);

            $deferred[0]();
            $this->assertFalse(Date::hasTestNow());
        } finally {
            Date::setTestNow();
        }
    }

    public function test_scripted_draws_fall_back_to_the_stream_once_the_script_is_exhausted(): void
    {
        $engineSeed = 998877;

        $source = new ScriptedDrawSource([7], new Randomizer(new Mt19937($engineSeed)));

        $first = $source->int(1, 100);
        $second = $source->int(1, 100);

        // A fresh randomizer seeded identically reproduces the exact value
        // the source's untouched fallback would have produced on its first
        // call — which is exactly what the second (unscripted) draw is.
        $expectedSecond = (new Randomizer(new Mt19937($engineSeed)))->getInt(1, 100);

        $this->assertSame(7, $first);
        $this->assertSame($expectedSecond, $second);
    }

    public function test_taking_the_raw_randomizer_marks_the_stream_source_opaque(): void
    {
        $source = new StreamDrawSource(new Randomizer(new Mt19937(1)));

        $source->randomizer();

        $this->assertTrue($source->isOpaque());
    }

    private function context(): Context
    {
        return new Context(new Randomizer(new Mt19937(1)));
    }

    private function dummyJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('only')];
            }

            public function invariants(): array
            {
                return [];
            }
        };
    }
}
