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

    public function test_mode_counts_accumulate_across_trails_of_the_same_mode(): void
    {
        $coverage = new TrailCoverage;

        $coverage->record($this->trail('shuffled', ['a']));
        $coverage->record($this->trail('shuffled', ['a', 'b']));

        // A broken accumulator would reset the mode count to 1 on every trail.
        $this->assertStringContainsString('(2 shuffled)', $coverage->describe());
    }

    public function test_describe_pads_step_names_to_the_widest_name_not_the_widest_count(): void
    {
        $coverage = new TrailCoverage;

        // "x" runs 10 times (a 2-digit count) but is 1 character long;
        // "longname" runs once (a 1-digit count) but is 8 characters long.
        // The padding width must come from the step-name lengths, not the counts.
        $coverage->record($this->trail('canonical', [...array_fill(0, 10, 'x'), 'longname']));

        $described = $coverage->describe();

        $this->assertStringContainsString(str_pad('x', strlen('longname')).'  10 runs in 1/1 trails', $described);
        $this->assertStringContainsString(str_pad('longname', strlen('longname')).'  1 runs in 1/1 trails', $described);
    }

    public function test_describe_lists_every_unseen_pair_when_exactly_ten_and_omits_the_more_suffix(): void
    {
        $coverage = new TrailCoverage;

        // 5 distinct steps recorded once in a fixed canonical order produce
        // exactly n*(n-1)/2 = 10 unseen (reverse) pairs out of 20 possible.
        $coverage->record($this->trail('canonical', ['a', 'b', 'c', 'd', 'e']));

        $described = $coverage->describe();

        $this->assertStringContainsString('Orderings of step pairs never observed (10 of 20):', $described);
        $this->assertSame(10, substr_count($described, ' before '));
        // The 10th (last) pair must be present — a slice starting at -1 or of length 9 would drop it.
        $this->assertStringContainsString('"e" before "d"', $described);
        // Exactly 10 is not > 10, so no truncation suffix should appear.
        $this->assertStringNotContainsString('... and', $described);
    }

    public function test_describe_truncates_unseen_pairs_after_ten_and_reports_the_remainder(): void
    {
        $coverage = new TrailCoverage;

        // 6 distinct steps recorded once in a fixed canonical order produce
        // n*(n-1)/2 = 15 unseen (reverse) pairs out of 30 possible.
        $coverage->record($this->trail('canonical', ['a', 'b', 'c', 'd', 'e', 'f']));

        $described = $coverage->describe();

        $this->assertStringContainsString('Orderings of step pairs never observed (15 of 30):', $described);
        // Exactly 10 individual lines — not 9, not 11, not all 15.
        $this->assertSame(10, substr_count($described, ' before '));
        $this->assertStringContainsString('... and 5 more', $described);
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
