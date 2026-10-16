<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Trail;
use Vusys\Runabout\TrailCoverage;

/** The coverage report leaves out the step pairs after() makes impossible. */
final class CoverageAfterExclusionTest extends TestCase
{
    public function test_pairs_after_forbids_are_left_out_transitively_unless_the_dependency_repeats(): void
    {
        $coverage = new TrailCoverage([$this->journey()]);
        $coverage->record($this->trail([[null, 'setup'], [null, 'again'], [null, 'w']]));

        // "w" before "setup" is impossible even through the repeatable "again";
        // "w" before "again" is reachable, because "again" can run after "w".
        $this->assertSame([['w', 'again']], $coverage->unseenPairs());
        $this->assertStringContainsString('Orderings of step pairs never observed (1 of 4, 2 excluded by after()):', $coverage->describe());
    }

    public function test_after_only_constrains_steps_within_one_interleaved_instance(): void
    {
        $coverage = new TrailCoverage([$this->journey(), $this->journey()]);
        $coverage->record($this->trail([['A', 'setup'], ['A', 'again'], ['A', 'w'], ['B', 'setup'], ['B', 'again'], ['B', 'w']]));

        $unseen = $coverage->unseenPairs();

        $this->assertNotContains(['A: w', 'A: setup'], $unseen);
        $this->assertNotContains(['B: w', 'B: setup'], $unseen);
        $this->assertContains(['B: w', 'A: setup'], $unseen);
        $this->assertContains(['A: w', 'A: again'], $unseen);
    }

    public function test_only_pairs_of_steps_that_ran_count_as_excluded(): void
    {
        $coverage = new TrailCoverage([new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('a'), Step::make('b')->after('a'), Step::make('c')->after('a')];
            }
        }]);
        $coverage->record($this->trail([[null, 'a'], [null, 'b']]));

        $this->assertSame([], $coverage->unseenPairs());
        $this->assertStringContainsString('All 1 orderings of step pairs observed (1 excluded by after()).', $coverage->describe());
    }

    public function test_unknown_and_cyclic_dependencies_are_left_to_the_runner_to_report(): void
    {
        $coverage = new TrailCoverage([new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('a')->after('b'), Step::make('b')->after('a'), Step::make('c')->after('ghost')];
            }
        }]);

        $this->assertSame(0, $coverage->trails());
    }

    private function journey(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('setup'),
                    Step::make('again')->repeatable(max: 3)->after('setup'),
                    Step::make('w')->after('again'),
                ];
            }
        };
    }

    /** @param list<array{string|null, string}> $tokens */
    private function trail(array $tokens): Trail
    {
        $trail = new Trail(1, 'shuffled');

        foreach ($tokens as [$label, $step]) {
            $trail->record($label, $step, 1);
        }

        return $trail;
    }
}
