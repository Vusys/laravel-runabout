<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\JourneyTestCase;
use Vusys\Runabout\Tests\TestCase;

/**
 * Built-in invariants signal a violation by throwing, so without counting a
 * journey checked only by invariants performed "no assertions" and PHPUnit
 * reported it as risky.
 */
final class InvariantAssertionCountTest extends TestCase
{
    use RunsJourneys;

    public function test_each_passing_invariant_check_counts_as_one_assertion(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        // Two steps, two invariants, one of them also checked at the start:
        // 2 × 2 per-step checks + 1 baseline check.
        $this->journey($this->journeyWithoutAssertions())->shuffles(0)->run();

        $this->assertSame(5, $this->numberOfAssertionsPerformed() - $before);
    }

    public function test_every_trail_counts(): void
    {
        $before = $this->numberOfAssertionsPerformed();

        $this->journey($this->journeyWithoutAssertions())->shuffles(2)->run();

        $this->assertSame(15, $this->numberOfAssertionsPerformed() - $before);
    }

    public function test_overriding_the_counter_with_null_leaves_the_count_alone(): void
    {
        $test = new class('test_run') extends JourneyTestCase
        {
            public function test_run(): void {}

            protected function journeyAssertionCounter(): ?\Closure
            {
                return null;
            }

            public function runJourney(Journey $journey): int
            {
                $before = $this->numberOfAssertionsPerformed();
                $this->journey($journey)->shuffles(0)->run();

                return $this->numberOfAssertionsPerformed() - $before;
            }
        };

        $this->assertSame(0, $test->runJourney($this->journeyWithoutAssertions()));
    }

    private function journeyWithoutAssertions(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('first')->act(fn (Context $ctx): mixed => $ctx->remember('ran', true)),
                    Step::make('second')->act(fn (Context $ctx): mixed => $ctx->remember('ran', true)),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('holds', function (): void {}),
                    Invariant::make('holds from the start', function (): void {})->fromStart(),
                ];
            }
        };
    }
}
