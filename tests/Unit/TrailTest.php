<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Draw;
use Vusys\Runabout\Trail;

final class TrailTest extends TestCase
{
    public function test_describe_numbers_steps_marks_the_last_and_counts_repeats(): void
    {
        $trail = new Trail(123, 'shuffled');
        $trail->record(null, 'publish post', 1);
        $trail->record(null, 'cast vote', 1);
        $trail->record(null, 'cast vote', 2);

        $this->assertSame(
            '   1. publish post'.PHP_EOL.
            '   2. cast vote'.PHP_EOL.
            '>  3. cast vote (run 2)',
            $trail->describe(),
        );
    }

    public function test_describe_can_leave_the_last_step_unmarked_for_passing_trails(): void
    {
        $trail = new Trail(123, 'shuffled');
        $trail->record(null, 'publish post', 1);
        $trail->record(null, 'cast vote', 1);

        $this->assertSame(
            '   1. publish post'.PHP_EOL.
            '   2. cast vote',
            $trail->describe(markLast: false),
        );
    }

    public function test_describe_handles_an_empty_trail(): void
    {
        $this->assertSame('  (no steps ran)', (new Trail(1, 'canonical'))->describe());
    }

    public function test_mode_and_shuffled_flag(): void
    {
        $this->assertSame('repeat-heavy', (new Trail(1, 'repeat-heavy'))->mode());
        $this->assertTrue((new Trail(1, 'repeat-heavy'))->isShuffled());
        $this->assertFalse((new Trail(1, 'canonical'))->isShuffled());
    }

    public function test_record_defaults_a_token_to_not_opaque(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);

        $this->assertFalse($trail->isOpaqueAt(0));
    }

    public function test_record_defaults_a_token_to_not_pinned(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);

        // Not observable through any public getter — pinned only surfaces
        // combined with non-empty draws — so inspect the recorded state directly.
        $pinned = new \ReflectionProperty(Trail::class, 'pinned');

        $this->assertSame([false], $pinned->getValue($trail));
    }

    public function test_attach_draws_defaults_forced_to_false(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);
        $trail->attachDraws([new Draw(0, 5, 3)], true);

        // Not forced, so the artifact stays a plain triple even though draws exist.
        $this->assertSame([null, 'step', 1], $trail->artifact()['steps'][0]);
    }

    public function test_is_opaque_at_returns_the_attached_value(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);
        $trail->attachDraws([], true);

        $this->assertTrue($trail->isOpaqueAt(0));
    }

    public function test_is_opaque_at_defaults_to_false_for_an_unrecorded_index(): void
    {
        $trail = new Trail(1, 'canonical');

        $this->assertFalse($trail->isOpaqueAt(0));
    }

    public function test_draws_at_defaults_to_empty_for_an_unrecorded_index(): void
    {
        $trail = new Trail(1, 'canonical');

        $this->assertSame([], $trail->drawsAt(0));
    }

    public function test_artifact_stays_a_triple_when_pinned_but_draws_are_empty(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);
        $trail->attachDraws([], true, forced: true);

        $this->assertSame([null, 'step', 1], $trail->artifact()['steps'][0]);
    }

    public function test_artifact_grows_a_fourth_element_when_pinned_with_draws(): void
    {
        $trail = new Trail(7, 'canonical');
        $trail->record('A', 'step', 1);
        $trail->attachDraws([new Draw(0, 5, 2), new Draw(0, 9, 7)], true, forced: true);

        $this->assertSame(
            ['seed' => 7, 'steps' => [['A', 'step', 1, [2, 7]]]],
            $trail->artifact(),
        );
    }

    public function test_describe_appends_the_drew_suffix_without_discarding_the_step_label(): void
    {
        $trail = new Trail(42, 'canonical');
        $trail->record(null, 'cast vote', 1);
        $trail->attachDraws([new Draw(0, 5, 3)], true, forced: true);

        $this->assertSame('>  1. cast vote [drew 3]', $trail->describe());
    }

    public function test_describe_omits_the_drew_suffix_when_pinned_but_draws_are_empty(): void
    {
        $trail = new Trail(1, 'canonical');
        $trail->record(null, 'step', 1);
        $trail->attachDraws([], true, forced: true);

        $this->assertSame('>  1. step', $trail->describe());
    }
}
