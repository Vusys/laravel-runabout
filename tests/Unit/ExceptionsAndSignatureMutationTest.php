<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use LogicException;
use PHPUnit\Framework\TestCase;
use ReflectionClass;
use RuntimeException;
use Vusys\Runabout\Exceptions\InvariantViolationException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Exceptions\OrderNotViableException;
use Vusys\Runabout\FailureSignature;
use Vusys\Runabout\Trail;

/**
 * White-box tests for the small, DB-free pieces of the failure-reporting
 * stack: the exception factories that compose failure messages, and the
 * signature that tells the shrinker "same bug" from "different bug".
 */
final class ExceptionsAndSignatureMutationTest extends TestCase
{
    // -- JourneyFailedException::wrap() ----------------------------------

    public function test_wrap_describes_the_canonical_order_by_name(): void
    {
        $trail = new Trail(seed: 5, mode: 'canonical');
        $trail->record(null, 'first step', 1);

        $exception = JourneyFailedException::wrap('SoloJourney', $trail, new RuntimeException('boom'));

        $this->assertStringContainsString('(canonical order, seed 5)', $exception->getMessage());
    }

    public function test_wrap_reports_a_shuffled_trails_own_mode_name(): void
    {
        $trail = new Trail(seed: 7, mode: 'shuffled');
        $trail->record(null, 'first step', 1);

        $exception = JourneyFailedException::wrap('SoloJourney', $trail, new RuntimeException('boom'));

        $this->assertStringContainsString('(shuffled, seed 7)', $exception->getMessage());
        $this->assertStringNotContainsString('canonical order', $exception->getMessage());
    }

    public function test_wrap_embeds_the_real_replay_artifact_json_for_a_replayed_trail(): void
    {
        $trail = new Trail(seed: 42, mode: 'replayed');
        $trail->record('A', 'first step', 1);

        $exception = JourneyFailedException::wrap('SoloJourney', $trail, new RuntimeException('boom'));

        $expectedArtifact = json_encode($trail->artifact());
        $this->assertIsString($expectedArtifact);
        $this->assertStringContainsString(
            sprintf("RUNABOUT_TRAIL='%s'.", $expectedArtifact),
            $exception->getMessage(),
        );
        $this->assertStringNotContainsString("RUNABOUT_TRAIL='{}'.", $exception->getMessage());
    }

    public function test_wrap_uses_exit_code_zero_and_preserves_the_cause(): void
    {
        $cause = new RuntimeException('boom');
        $trail = new Trail(seed: 1, mode: 'canonical');
        $trail->record(null, 'first step', 1);

        $exception = JourneyFailedException::wrap('SoloJourney', $trail, $cause);

        $this->assertSame(0, $exception->getCode());
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame($trail, $exception->trail());
    }

    // -- JourneyFailedException::shrunk() --------------------------------

    public function test_shrunk_composes_the_headline_before_the_full_trail_replay_hint(): void
    {
        $innerTrail = new Trail(seed: 7, mode: 'replayed');
        $innerTrail->record('A', 'first step', 1);
        $shrunkReplay = JourneyFailedException::wrap('SoloJourney', $innerTrail, new RuntimeException('shrunk cause'));

        $exception = JourneyFailedException::shrunk($shrunkReplay, originalExecutions: 10, originalSeed: 99, replays: 3);

        $expected = sprintf(
            "Shrunk from %d executions to %d (%d replays):\n%s",
            10,
            count($innerTrail->tokens()),
            3,
            $shrunkReplay->getMessage(),
        ).sprintf("\nReplay the full %d-execution trail with RUNABOUT_SEED=%d.", 10, 99);

        $this->assertSame($expected, $exception->getMessage());
    }

    public function test_shrunk_uses_exit_code_zero_and_carries_the_shrunk_trail_and_cause(): void
    {
        $cause = new RuntimeException('shrunk cause');
        $innerTrail = new Trail(seed: 7, mode: 'replayed');
        $innerTrail->record('A', 'first step', 1);
        $shrunkReplay = JourneyFailedException::wrap('SoloJourney', $innerTrail, $cause);

        $exception = JourneyFailedException::shrunk($shrunkReplay, originalExecutions: 10, originalSeed: 99, replays: 3);

        $this->assertSame(0, $exception->getCode());
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame($innerTrail, $exception->trail());
    }

    // -- FailureSignature::from() -----------------------------------------

    public function test_from_recognises_an_invariant_violation_as_the_cause(): void
    {
        $invariantCause = InvariantViolationException::make('books balance', 'checkout', new RuntimeException('drift'));
        $trail = new Trail(seed: 1, mode: 'canonical');
        $trail->record(null, 'checkout', 1);
        $failure = JourneyFailedException::wrap('BookingJourney', $trail, $invariantCause);

        $signature = FailureSignature::from($failure);

        $this->assertSame('invariant', $signature->kind);
        $this->assertSame('books balance', $signature->name);
        $this->assertSame('', $signature->causeClass);
        $this->assertSame('invariant "books balance"', $signature->describe());
    }

    public function test_from_falls_back_to_the_failing_step_and_cause_class(): void
    {
        $cause = new RuntimeException('boom');
        $trail = new Trail(seed: 1, mode: 'canonical');
        $trail->record(null, 'checkout', 1);
        $failure = JourneyFailedException::wrap('BookingJourney', $trail, $cause);

        $signature = FailureSignature::from($failure);

        $this->assertSame('step', $signature->kind);
        $this->assertSame('checkout', $signature->name);
        $this->assertSame(RuntimeException::class, $signature->causeClass);
        $this->assertSame('step "checkout" throwing '.RuntimeException::class, $signature->describe());
    }

    // -- FailureSignature::matches() ---------------------------------------

    public function test_matches_requires_kind_name_and_cause_class_to_all_agree(): void
    {
        $base = $this->signature('step', 'checkout', RuntimeException::class);

        $this->assertTrue($base->matches($this->signature('step', 'checkout', RuntimeException::class)));

        $this->assertFalse($base->matches($this->signature('invariant', 'checkout', RuntimeException::class)), 'kind must match');
        $this->assertFalse($base->matches($this->signature('step', 'other step', RuntimeException::class)), 'name must match');
        $this->assertFalse($base->matches($this->signature('step', 'checkout', LogicException::class)), 'cause class must match');
    }

    private function signature(string $kind, string $name, string $causeClass): FailureSignature
    {
        $reflection = new ReflectionClass(FailureSignature::class);
        $signature = $reflection->newInstanceWithoutConstructor();

        foreach (['kind' => $kind, 'name' => $name, 'causeClass' => $causeClass] as $property => $value) {
            $reflection->getProperty($property)->setValue($signature, $value);
        }

        return $signature;
    }

    // -- InvariantViolationException ---------------------------------------

    public function test_make_composes_the_violation_message_and_preserves_the_cause(): void
    {
        $cause = new RuntimeException('drifted');
        $exception = InvariantViolationException::make('books balance', 'checkout', $cause);

        $this->assertSame('Invariant "books balance" violated after step "checkout": drifted', $exception->getMessage());
        $this->assertSame('books balance', $exception->invariant);
        $this->assertSame('checkout', $exception->step);
        $this->assertSame($cause, $exception->getPrevious());
        $this->assertSame(0, $exception->getCode());
    }

    // -- OrderNotViableException ---------------------------------------------

    public function test_constructing_composes_the_not_viable_message(): void
    {
        $exception = new OrderNotViableException('draft post');

        $this->assertSame('Step "draft post" is not enabled in this order.', $exception->getMessage());
        $this->assertSame('draft post', $exception->stepName);
    }
}
