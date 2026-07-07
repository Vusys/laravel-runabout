<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;

/**
 * trail()'s artifact parsing (parseArtifact/decodeArtifact) is pure and needs
 * no database: every case here feeds a decoded artifact straight to
 * PendingJourney with a no-op wrapper (as ExecutionModesTest does) and reads
 * either the thrown InvalidJourneyException's exact message or the resulting
 * Trail's observable effect (a forced draw actually being applied).
 */
final class PendingJourneyArtifactTest extends TestCase
{
    // --- top-level artifact shape: {seed, steps} -------------------------

    public function test_a_non_integer_seed_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('A trail artifact needs an integer "seed" and a "steps" list.');

        $this->pending()->trail(['seed' => 'not-an-int', 'steps' => []])->run();
    }

    public function test_a_non_array_steps_list_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('A trail artifact needs an integer "seed" and a "steps" list.');

        $this->pending()->trail(['seed' => 1, 'steps' => 'not-an-array'])->run();
    }

    public function test_a_missing_seed_or_steps_key_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('A trail artifact needs an integer "seed" and a "steps" list.');

        $this->pending()->trail(['steps' => []])->run();
    }

    // --- each step: [label, step, run] triple -----------------------------

    public function test_a_step_missing_its_label_slot_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be a [label, step, run] triple.');

        // Explicit keys 1 and 2 only — slot 0 (label) is missing.
        $this->pending()->trail(['seed' => 1, 'steps' => [[1 => 'a', 2 => 1]]])->run();
    }

    public function test_a_step_missing_its_name_slot_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be a [label, step, run] triple.');

        // Explicit keys 0 and 2 only — slot 1 (step name) is missing.
        $this->pending()->trail(['seed' => 1, 'steps' => [[0 => null, 2 => 1]]])->run();
    }

    public function test_a_step_missing_its_run_slot_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be a [label, step, run] triple.');

        // Explicit keys 0 and 1 only — slot 2 (run) is missing.
        $this->pending()->trail(['seed' => 1, 'steps' => [[0 => null, 1 => 'a']]])->run();
    }

    public function test_a_non_array_step_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be a [label, step, run] triple.');

        $this->pending()->trail(['seed' => 1, 'steps' => ['not-an-array']])->run();
    }

    public function test_a_non_string_non_null_label_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be [label|null, step string, run int].');

        $this->pending()->trail(['seed' => 1, 'steps' => [[123, 'a', 1]]])->run();
    }

    public function test_a_non_string_step_name_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be [label|null, step string, run int].');

        $this->pending()->trail(['seed' => 1, 'steps' => [[null, 123, 1]]])->run();
    }

    public function test_a_non_int_run_is_rejected(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('Each trail step must be [label|null, step string, run int].');

        $this->pending()->trail(['seed' => 1, 'steps' => [[null, 'a', 'one']]])->run();
    }

    public function test_a_string_label_is_a_valid_slot(): void
    {
        // A labelled ("A"/"B") interleaved token — this must reach the
        // non-viability check, not the shape/type validation.
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('not viable');

        $this->pending()->trail(['seed' => 1, 'steps' => [['A', 'a', 1]]])->run();
    }

    // --- optional fourth element: forced draws -----------------------------

    public function test_a_forced_draw_on_the_only_step_pins_its_value(): void
    {
        $captured = null;

        $journey = $this->journey([
            Step::make('draw')->act(function (Context $ctx) use (&$captured): void {
                $captured = $ctx->randomInt(1, 100);
            }),
        ]);

        // Seed 1's natural stream draws 97 for this token; 42 proves the
        // forced value (not the stream) was used.
        $this->pending($journey)
            ->trail(['seed' => 1, 'steps' => [[null, 'draw', 1, [42]]]])
            ->run();

        $this->assertSame(42, $captured);
    }

    public function test_a_forced_draw_on_a_later_step_pins_the_right_token(): void
    {
        $captured = null;

        $journey = $this->journey([
            Step::make('noop'),
            Step::make('draw')->act(function (Context $ctx) use (&$captured): void {
                $captured = $ctx->randomInt(1, 100);
            }),
        ]);

        // Only the second token (index 1) carries forced draws; a mis-keyed
        // index (off by one) would leave the natural stream value (97) in
        // place instead of the pinned 42.
        $this->pending($journey)
            ->trail(['seed' => 1, 'steps' => [[null, 'noop', 1], [null, 'draw', 1, [42]]]])
            ->run();

        $this->assertSame(42, $captured);
    }

    public function test_forced_draws_must_be_a_list(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('forced draws (element 4) must be a list of integers');

        $this->pending()->trail(['seed' => 1, 'steps' => [[null, 'a', 1, 'not-an-array']]])->run();
    }

    public function test_forced_draws_must_be_all_integers(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('forced draws (element 4) must be a list of integers');

        $this->pending()->trail(['seed' => 1, 'steps' => [[null, 'a', 1, [1, 'two']]]])->run();
    }

    public function test_a_step_with_no_fourth_element_draws_from_the_stream(): void
    {
        $captured = null;

        $journey = $this->journey([
            Step::make('draw')->act(function (Context $ctx) use (&$captured): void {
                $captured = $ctx->randomInt(1, 100);
            }),
        ]);

        $this->pending($journey)
            ->trail(['seed' => 1, 'steps' => [[null, 'draw', 1]]])
            ->run();

        $this->assertSame(97, $captured, 'With no forced draws, the token draws its natural stream value.');
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
        };
    }

    private function pending(?Journey $journey = null): PendingJourney
    {
        $journey ??= $this->journey([Step::make('a'), Step::make('b')]);

        return new PendingJourney($journey, fn (Closure $trail) => $trail());
    }
}
