<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
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
}
