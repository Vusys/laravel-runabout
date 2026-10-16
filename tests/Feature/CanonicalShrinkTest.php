<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\TestCase;

final class CanonicalShrinkTest extends TestCase
{
    public function test_a_canonical_failure_is_shrunk_and_replays_as_an_artifact(): void
    {
        try {
            $this->pending()->shuffles(0)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertSame(['create', 'move'], $e->trail()->steps());
            $this->assertStringStartsWith('Shrunk the canonical order from 4 executions to 2 (', $e->getMessage());
            $this->assertStringContainsString('Replay with RUNABOUT_TRAIL=', $e->getMessage());
            $this->assertStringEndsWith("\nRe-run the journey to reproduce the full 4-execution trail.", $e->getMessage());
            $this->assertStringNotContainsString('RUNABOUT_SEED=', $e->getMessage(), 'a seed replays a shuffled trail, not the canonical order.');
            $artifact = $e->trail()->artifact();
        }

        try {
            $this->pending()->trail($artifact)->run();
            $this->fail('expected the artifact to reproduce the failure');
        } catch (JourneyFailedException $e) {
            $this->assertSame(['create', 'move'], $e->trail()->steps());
        }
    }

    public function test_a_canonical_failure_that_needs_every_execution_still_shrinks_its_draws(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('draw')->act(fn (Context $ctx): int => $ctx->randomInt(1, 1_000_000)),
                    Step::make('check')->after('draw')->act(function (): never {
                        throw new RuntimeException('always fails');
                    }),
                ];
            }
        };

        try {
            (new PendingJourney($journey, fn (Closure $trail) => $trail()))->shuffles(0)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertStringStartsWith('Shrunk drawn values in the canonical order; all 2 executions are needed (', $e->getMessage());
            $this->assertStringContainsString('1. draw [drew 1]', $e->getMessage());
        }
    }

    public function test_a_canonical_failure_is_not_shrunk_when_runabout_shrink_is_zero(): void
    {
        putenv('RUNABOUT_SHRINK=0');

        try {
            $this->pending()->shuffles(0)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertSame(['create', 'rename', 'rename again', 'move'], $e->trail()->steps());
            $this->assertStringNotContainsString('Shrunk', $e->getMessage());
        } finally {
            putenv('RUNABOUT_SHRINK');
        }
    }

    private function pending(): PendingJourney
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('create'),
                    Step::make('rename')->after('create'),
                    Step::make('rename again')->after('create'),
                    Step::make('move')->after('create')->act(function (Context $ctx): never {
                        throw new RuntimeException('moved document lost its parent');
                    }),
                ];
            }
        };

        return new PendingJourney($journey, fn (Closure $trail) => $trail());
    }
}
