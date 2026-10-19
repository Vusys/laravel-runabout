<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Replay\TrailToken;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\TestCase;
use Vusys\Runabout\Trail;

/** Swallows STDERR so verbose output can be asserted on (see PendingJourneyCoverageStderrFilter). */
final class AlsoTrailsStderrFilter extends \php_user_filter
{
    public static string $buffer = '';

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            $before = strlen(self::$buffer);
            self::$buffer .= $bucket->data;
            $consumed += strlen(self::$buffer) - $before;
        }

        return PSFS_PASS_ON;
    }
}

/**
 * alsoTrails() keeps known-risky orderings in every run, after the canonical
 * trail and before the shuffles, instead of hoping the shuffles reach them.
 */
final class AlsoTrailsTest extends TestCase
{
    use RunsJourneys;

    public function test_each_ordering_runs_after_the_canonical_trail(): void
    {
        $this->assertSame(
            [['canonical', ['first', 'second']], ['replayed', ['second', 'first']], ['replayed', ['first', 'first', 'second']]],
            array_map(fn (Trail $trail): array => [$trail->mode(), $trail->steps()], $this->trailsOf(
                $this->journeyOf(),
                fn ($journey) => $journey->alsoTrails(['second', 'first'], ['first', 'first', 'second']),
            )),
        );
    }

    public function test_a_repeated_name_is_the_steps_next_run(): void
    {
        $trails = $this->trailsOf($this->journeyOf(), fn ($journey) => $journey->alsoTrails(['first', 'first', 'second']));

        $this->assertSame([1, 2, 1], array_map(fn (TrailToken $token): int => $token->run, $trails[1]->tokens()));
    }

    public function test_an_ordering_draws_the_canonical_trails_values(): void
    {
        $drawn = [];
        $journey = $this->journeyOf(function (Context $ctx) use (&$drawn): void {
            $drawn[] = $ctx->randomInt(1, 1_000_000);
        });

        $this->trailsOf($journey, fn ($pending) => $pending->alsoTrails(['second', 'first']));

        // Canonical: first, second. Ordering: second, first. Same executions, same draws.
        $this->assertSame([$drawn[0], $drawn[1]], [$drawn[3], $drawn[2]]);
    }

    public function test_a_failing_ordering_prints_the_artifact_that_replays_it(): void
    {
        $journey = $this->journeyOf(fn (Context $ctx): null => $ctx->ranBefore('second') && $ctx->timesRan('first') > 0
            ? Assert::fail('first ran again after second')
            : null);

        try {
            $this->journey($journey)->shuffles(0)->alsoTrails(['first', 'second', 'first'])->run();
            $this->fail('Expected the ordering to fail.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString('failed (replayed, seed ', $failure->getMessage());
            $this->assertMatchesRegularExpression('/Replay with RUNABOUT_TRAIL=\'\{"seed":\d+,"steps":\[\[null,"first",1\],\[null,"second",1\],\[null,"first",2\]\]\}\'\./', $failure->getMessage());
        }
    }

    public function test_an_ordering_the_constraints_rule_out_fails_the_run(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('alsoTrails() ordering 2 is not viable: Step "after first" is not enabled in this order. Check the step\'s name, and that its after()/when() constraints allow it at that point.');

        $this->journey($this->journeyOf(after: true))->shuffles(0)->alsoTrails(['first', 'after first'], ['after first'])->run();
    }

    public function test_an_unknown_step_fails_the_run(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('alsoTrails() ordering 1 is not viable: Step "nope (unknown step)" is not enabled in this order.');

        $this->journey($this->journeyOf())->shuffles(0)->alsoTrails(['nope'])->run();
    }

    public function test_an_empty_ordering_fails_the_run(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('alsoTrails() ordering 1 is empty.');

        $this->journey($this->journeyOf())->shuffles(0)->alsoTrails([])->run();
    }

    public function test_interleaved_orderings_name_their_instances(): void
    {
        $trails = [];
        $this->interleave($this->journeyOf(), $this->journeyOf())
            ->shuffles(0)
            ->alsoTrails(['B: first', 'A: first', 'B: second', 'A: second'])
            ->onTrail(function (Trail $trail) use (&$trails): void {
                $trails[] = $trail->steps();
            })
            ->run();

        $this->assertSame(['B: first', 'A: first', 'B: second', 'A: second'], $trails[1]);
    }

    public function test_an_interleaved_ordering_without_labels_fails_the_run(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('alsoTrails() ordering 1 names "first" without an instance label; in an interleaved run write it as "A: first".');

        $this->interleave($this->journeyOf(), $this->journeyOf())->shuffles(0)->alsoTrails(['first'])->run();
    }

    public function test_an_interleaved_step_name_may_contain_a_colon(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('note: read')->act(fn (): null => null)];
            }
        };

        $trails = [];
        $this->interleave($journey, $journey)->shuffles(0)->alsoTrails(['B: note: read', 'A: note: read'])->onTrail(function (Trail $trail) use (&$trails): void {
            $trails[] = $trail->steps();
        })->run();

        $this->assertSame(['B: note: read', 'A: note: read'], $trails[1]);
    }

    public function test_an_interleaved_label_must_be_an_instance_label(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('alsoTrails() ordering 1 names "a: first" without an instance label');

        $this->interleave($this->journeyOf(), $this->journeyOf())->shuffles(0)->alsoTrails(['a: first'])->run();
    }

    public function test_verbose_output_counts_the_orderings_in_the_total(): void
    {
        putenv('RUNABOUT_VERBOSE=1');

        try {
            $output = $this->captureStderr(fn () => $this->journey($this->journeyOf())->shuffles(1)->alsoTrails(['second', 'first'])->run());
        } finally {
            putenv('RUNABOUT_VERBOSE');
        }

        $this->assertStringContainsString('] trail 1/3 (canonical', $output);
        $this->assertStringContainsString('] trail 2/3 (replayed', $output);
        $this->assertStringContainsString('] trail 3/3 (shuffled', $output);
    }

    /**
     * @param  (Closure(Context): mixed)|null  $act  Run by both steps.
     */
    private function journeyOf(?Closure $act = null, bool $after = false): Journey
    {
        return new class($act ?? fn (): null => null, $after) extends Journey
        {
            /** @param Closure(Context): mixed $act */
            public function __construct(private readonly Closure $act, private readonly bool $after) {}

            public function steps(): array
            {
                return $this->after
                    ? [Step::make('first')->act($this->act), Step::make('after first')->after('first')->act($this->act)]
                    : [Step::make('first')->repeatable(max: 2)->act($this->act), Step::make('second')->act($this->act)];
            }
        };
    }

    /**
     * @param  Closure(PendingJourney): PendingJourney  $configure
     * @return list<Trail>
     */
    private function trailsOf(Journey $journey, Closure $configure): array
    {
        $trails = [];
        $configure($this->journey($journey)->shuffles(0))
            ->onTrail(function (Trail $trail) use (&$trails): void {
                $trails[] = $trail;
            })
            ->run();

        return $trails;
    }

    /** @param Closure(): void $fn */
    private function captureStderr(Closure $fn): string
    {
        static $registered = false;

        if (! $registered) {
            stream_filter_register('also-trails-capture', AlsoTrailsStderrFilter::class);
            $registered = true;
        }

        AlsoTrailsStderrFilter::$buffer = '';
        $handle = stream_filter_append(STDERR, 'also-trails-capture');

        try {
            $fn();
        } finally {
            if ($handle !== false) {
                stream_filter_remove($handle);
            }
        }

        return AlsoTrailsStderrFilter::$buffer;
    }
}
