<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use Closure;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Trail;
use Vusys\Runabout\TrailCoverage;

final class TrailCoverageTest extends TestCase
{
    public function test_it_counts_trails_runs_and_distinct_orderings(): void
    {
        $coverage = new TrailCoverage;

        $coverage->record($this->trail('canonical', ['a', 'b', 'c']));
        $coverage->record($this->trail('shuffled', ['b', 'a', 'b', 'c']));
        $coverage->record($this->trail('shuffled', ['b', 'a', 'b', 'c']));

        $this->assertSame(3, $coverage->trails());
        $this->assertSame(2, $coverage->distinctOrderings());
        $this->assertSame(['a' => 3, 'b' => 5, 'c' => 3], $coverage->stepRuns());
    }

    public function test_pairs_count_trails_not_occurrences(): void
    {
        $coverage = new TrailCoverage;

        // "a" precedes "b" twice within one trail: still one trail.
        $coverage->record($this->trail('shuffled', ['a', 'b', 'a', 'b']));
        $coverage->record($this->trail('shuffled', ['b', 'a']));

        $this->assertSame(1, $coverage->timesBefore('a', 'b'));
        // The second trail — and the repeat in the first — put "b" before "a".
        $this->assertSame(2, $coverage->timesBefore('b', 'a'));
        $this->assertSame(0, $coverage->timesBefore('a', 'a'));
    }

    public function test_unseen_pairs_lists_orderings_no_trail_explored(): void
    {
        $coverage = new TrailCoverage;

        $coverage->record($this->trail('canonical', ['a', 'b', 'c']));
        $coverage->record($this->trail('shuffled', ['b', 'a', 'c']));

        $this->assertSame([['c', 'a'], ['c', 'b']], $coverage->unseenPairs());
    }

    public function test_describe_summarises_modes_steps_and_missing_pairs(): void
    {
        $coverage = new TrailCoverage;

        $coverage->record($this->trail('canonical', ['a', 'b']));
        $coverage->record($this->trail('shuffled', ['a', 'b', 'b']));

        $described = $coverage->describe();

        $this->assertStringContainsString('2 trails (1 canonical, 1 shuffled), 2 distinct orderings', $described);
        $this->assertStringContainsString('a  2 runs in 2/2 trails', $described);
        $this->assertStringContainsString('b  3 runs in 2/2 trails', $described);
        $this->assertStringContainsString('Orderings of step pairs never observed (1 of 2):', $described);
        $this->assertStringContainsString('"b" before "a"', $described);
    }

    public function test_describe_reports_full_pair_coverage(): void
    {
        $coverage = new TrailCoverage;

        $coverage->record($this->trail('shuffled', ['a', 'b']));
        $coverage->record($this->trail('shuffled', ['b', 'a']));

        $this->assertStringContainsString('All 2 orderings of step pairs observed.', $coverage->describe());
    }

    public function test_describe_with_no_trails(): void
    {
        $this->assertSame('  (no trails recorded)', (new TrailCoverage)->describe());
    }

    public function test_a_real_run_never_explores_a_constrained_ordering(): void
    {
        $coverage = new TrailCoverage;

        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('first'),
                    Step::make('second')->after('first'),
                    Step::make('free'),
                ];
            }

            public function invariants(): array
            {
                return [];
            }
        };

        (new PendingJourney($journey, fn (Closure $trail) => $trail()))
            ->shuffles(25)
            ->onTrail($coverage->record(...))
            ->run();

        $this->assertSame(26, $coverage->trails());
        // The after() constraint makes this ordering impossible...
        $this->assertSame(0, $coverage->timesBefore('second', 'first'));
        $this->assertContains(['second', 'first'], $coverage->unseenPairs());
        // ...while the unconstrained step gets explored on both sides.
        $this->assertGreaterThan(0, $coverage->timesBefore('free', 'first'));
        $this->assertGreaterThan(0, $coverage->timesBefore('first', 'free'));
        $this->assertGreaterThan(1, $coverage->distinctOrderings());
    }

    /**
     * @param  'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'|'replayed'  $mode
     * @param  list<string>  $steps
     */
    private function trail(string $mode, array $steps): Trail
    {
        $trail = new Trail(1, $mode);
        $runs = [];

        foreach ($steps as $step) {
            $runs[$step] = ($runs[$step] ?? 0) + 1;
            $trail->record(null, $step, $runs[$step]);
        }

        return $trail;
    }
}
