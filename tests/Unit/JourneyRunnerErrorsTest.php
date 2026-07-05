<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use RuntimeException;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;

/** The runner's failure paths: invalid journeys, impossible trails, and teardown failures. */
final class JourneyRunnerErrorsTest extends TestCase
{
    public function test_a_journey_with_no_steps_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('defines no steps');

        (new JourneyRunner)->run($this->journey([]), seed: 1);
    }

    public function test_a_canonical_order_with_a_disabled_step_is_rejected(): void
    {
        // Enabled only after a step that comes later in the declared order:
        // shuffles can find a valid order, but the canonical order cannot be
        // a valid trail, and that is a journey-definition error.
        $journey = $this->journey([
            Step::make('needs the opener')->after('opener'),
            Step::make('opener'),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1, shuffle: false);
            $this->fail('Expected the canonical order to be rejected.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('the canonical order must be a valid trail', $e->getMessage());
            $this->assertStringContainsString('before any step ran', $e->getMessage());
        }
    }

    public function test_a_runaway_trail_is_reported(): void
    {
        // "spin" stays enabled forever, "never" stays disabled forever: no
        // deadlock (something is always pickable), so the tick cap is the
        // only thing standing between the picker and an infinite trail.
        $journey = $this->journey([
            Step::make('spin')->repeatable(),
            Step::make('never')->when(fn (): bool => false),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected a runaway-trail failure.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Runaway trail', $e->getMessage());
            $this->assertStringContainsString('Bound your repeatable() steps', $e->getMessage());
        }
    }

    public function test_a_failing_teardown_fails_the_trail(): void
    {
        $journey = $this->journey([
            Step::make('tidy up after')->teardown(function (): never {
                throw new RuntimeException('the teardown exploded');
            }),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1);
            $this->fail('Expected the teardown failure to fail the trail.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('the teardown exploded', $e->getMessage());
        }
    }

    public function test_a_teardown_failure_never_masks_the_primary_failure(): void
    {
        $journey = $this->journey([
            Step::make('set up')->teardown(function (): never {
                throw new RuntimeException('the teardown exploded');
            }),
            Step::make('blow up')->after('set up')->act(function (): never {
                throw new RuntimeException('the primary failure');
            }),
        ]);

        try {
            (new JourneyRunner)->run($journey, seed: 1, shuffle: false);
            $this->fail('Expected the primary failure to be reported.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('the primary failure', $e->getMessage());
            $this->assertStringNotContainsString('the teardown exploded', $e->getMessage());
        }
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
}
