<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use LogicException;
use PHPUnit\Framework\SkippedWithMessageException;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\TestCase;

/** Swallows STDERR so the unfired-issue warning can be asserted on. */
final class KnownIssuesStderrFilter extends \php_user_filter
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
 * knownIssue() tolerates a bug already known about: the run keeps exploring,
 * and is skipped (not failed) when known issues are all it found.
 */
final class KnownIssuesTest extends TestCase
{
    use RunsJourneys;

    private int $checks = 0;

    public function test_a_run_that_only_hits_a_known_invariant_is_skipped_with_its_report(): void
    {
        $message = $this->skipMessage(fn () => $this->journey($this->journeyOf(breaks: true))->shuffles(3)->knownIssue('balanced', reason: 'ticket 12')->run());

        $this->assertStringContainsString(' failed only on known issues:', $message);
        $this->assertStringContainsString('- invariant "balanced" (ticket 12), 1 trail. First hit:', $message);
        $this->assertStringContainsString('Invariant "balanced" violated after step "first"', $message);
        $this->assertMatchesRegularExpression('/Replay with RUNABOUT_TRAIL=\'\{"seed":\d+,"steps":\[\[null,"first",1\]\]\}\'\.$/', $message);
    }

    public function test_a_hit_invariant_is_not_checked_for_the_rest_of_the_run(): void
    {
        $this->skipMessage(fn () => $this->journey($this->journeyOf(breaks: true))->shuffles(3)->knownIssue('balanced')->run());

        // The canonical trail checks once and fails; the shuffles never check it again.
        $this->assertSame(1, $this->checks);
    }

    public function test_an_invariant_object_names_the_issue(): void
    {
        $journey = $this->journeyOf(breaks: true);

        $message = $this->skipMessage(fn () => $this->journey($journey)->shuffles(0)->knownIssue($journey->invariants()[0])->run());

        $this->assertStringContainsString('- invariant "balanced", 1 trail.', $message);
    }

    public function test_a_known_step_failure_is_counted_on_every_trail_it_fires(): void
    {
        $message = $this->skipMessage(fn () => $this->journey($this->journeyOf(throws: true))->shuffles(2)->knownIssue(step: 'second', throwing: LogicException::class)->run());

        $this->assertStringContainsString('- step "second" throwing LogicException, 3 trails. First hit:', $message);
    }

    public function test_a_step_alone_or_an_exception_alone_matches(): void
    {
        $this->assertStringContainsString('- step "second", 1 trail.', $this->skipMessage(
            fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue(step: 'second')->run(),
        ));

        $this->assertStringContainsString('- a step throwing Exception, 1 trail.', $this->skipMessage(
            fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue(throwing: \Exception::class)->run(),
        ));
    }

    public function test_a_different_exception_from_the_known_step_still_fails(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue(step: 'second', throwing: InvalidJourneyException::class)->run());
    }

    public function test_a_different_step_still_fails(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue(step: 'first')->run());
    }

    public function test_an_invariant_issue_does_not_match_a_step_failure(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue('second')->run());
    }

    public function test_a_new_failure_fails_with_the_known_hits_noted(): void
    {
        try {
            // The canonical trail stops at the known issue; the shuffle reaches "second".
            $this->journey($this->journeyOf(breaks: true, throws: true))->shuffles(1)->knownIssue('balanced')->run();
            $this->fail('Expected the new failure to fail the run.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString("second is broken\n", $failure->getMessage());
            $this->assertStringContainsString("Known issues also fired in this run:\n- invariant \"balanced\", 1 trail. First hit:", $failure->getMessage());
        }
    }

    public function test_replaying_an_environment_seed_ignores_known_issues(): void
    {
        putenv('RUNABOUT_SEED=1');

        try {
            $this->failureOf(fn () => $this->journey($this->journeyOf(breaks: true))->knownIssue('balanced')->run());
        } finally {
            putenv('RUNABOUT_SEED');
        }
    }

    public function test_the_issue_that_matches_is_found_among_several(): void
    {
        $message = '';
        $output = $this->captureStderr(function () use (&$message): void {
            $message = $this->skipMessage(fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue('balanced')->knownIssue(step: 'first')->knownIssue(step: 'second')->run());
        });

        $this->assertStringContainsString('- step "second", 1 trail.', $message);
        $this->assertStringContainsString('known issue invariant "balanced"; step "first" did not reproduce', $output);
    }

    public function test_a_suppressed_invariant_leaves_the_others_checked(): void
    {
        $failure = $this->failureOf(fn () => $this->journey($this->journeyOf(breaks: true, alsoBroken: true))->shuffles(1)->knownIssue('balanced')->run());

        $this->assertStringContainsString('Invariant "also balanced" violated', $failure->getMessage());
    }

    public function test_a_step_name_may_contain_a_colon(): void
    {
        $this->assertStringContainsString('- step "note: read", 1 trail.', $this->skipMessage(
            fn () => $this->journey($this->journeyOf(throws: true, second: 'note: read'))->shuffles(0)->knownIssue(step: 'note: read')->run(),
        ));

        $this->assertStringContainsString('- step "note: read", 1 trail.', $this->skipMessage(
            fn () => $this->interleave($this->journeyOf(throws: true, second: 'note: read'), $this->journeyOf(second: 'note: read'))->shuffles(0)->knownIssue(step: 'note: read')->run(),
        ));
    }

    public function test_an_exhaustive_run_still_fails_on_a_new_failure(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(throws: true))->exhaustive()->knownIssue(step: 'first')->run());
    }

    public function test_a_new_failure_without_known_hits_is_reported_as_is(): void
    {
        try {
            $this->journey($this->journeyOf(throws: true))->shuffles(0)->knownIssue('balanced')->run();
            $this->fail('Expected the failure to fail the run.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringNotContainsString('Known issues', $failure->getMessage());
        }
    }

    public function test_an_issue_that_never_fires_warns(): void
    {
        $output = $this->captureStderr(fn () => $this->journey($this->journeyOf())->shuffles(0)->knownIssue('balanced', reason: 'ticket 12')->run());

        $this->assertStringStartsWith("\n[runabout] Journey ", $output);
        $this->assertStringEndsWith(": known issue invariant \"balanced\" (ticket 12) did not reproduce in this run; has it been fixed?\n", $output);
    }

    public function test_an_issue_that_never_fires_fails_under_strict_known_issues(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('known issue invariant "balanced" did not reproduce in this run; has it been fixed? Remove the knownIssue(), or drop strictKnownIssues()');

        $this->journey($this->journeyOf())->shuffles(0)->knownIssue('balanced')->strictKnownIssues()->run();
    }

    public function test_a_fired_issue_satisfies_strict_known_issues(): void
    {
        $message = '';
        $output = $this->captureStderr(function () use (&$message): void {
            $message = $this->skipMessage(fn () => $this->journey($this->journeyOf(breaks: true))->shuffles(0)->knownIssue('balanced')->strictKnownIssues()->run());
        });

        $this->assertSame('', $output);
        $this->assertStringContainsString('failed only on known issues:', $message);
    }

    public function test_a_journey_without_a_skipper_throws_the_summary(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('failed only on known issues:');

        (new PendingJourney($this->journeyOf(breaks: true), fn (Closure $trail) => $trail()))->shuffles(0)->knownIssue('balanced')->run();
    }

    public function test_replaying_a_seed_ignores_known_issues(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(breaks: true))->seed(1)->knownIssue('balanced')->run());
    }

    public function test_replaying_a_trail_ignores_known_issues(): void
    {
        $this->failureOf(fn () => $this->journey($this->journeyOf(breaks: true))->trail(['seed' => 1, 'steps' => [[null, 'first', 1]]])->knownIssue('balanced')->run());
    }

    public function test_named_and_exhaustive_trails_are_tolerated_too(): void
    {
        $this->assertStringContainsString('3 trails', $this->skipMessage(
            fn () => $this->journey($this->journeyOf(throws: true))->shuffles(0)->alsoTrails(['first', 'second'], ['second'])->knownIssue(step: 'second')->run(),
        ));

        $this->assertStringContainsString('trails. First hit:', $this->skipMessage(
            fn () => $this->journey($this->journeyOf(throws: true))->exhaustive()->knownIssue(step: 'second')->run(),
        ));
    }

    public function test_interleaved_failures_match_without_their_instance_label(): void
    {
        $this->assertStringContainsString('- invariant "balanced", 1 trail.', $this->skipMessage(
            fn () => $this->interleave($this->journeyOf(breaks: true), $this->journeyOf())->shuffles(0)->knownIssue('balanced')->run(),
        ));

        $this->assertStringContainsString('- step "second", 1 trail.', $this->skipMessage(
            fn () => $this->interleave($this->journeyOf(throws: true), $this->journeyOf())->shuffles(0)->knownIssue(step: 'second')->run(),
        ));
    }

    public function test_an_issue_needs_something_to_match(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('knownIssue() needs an invariant, or a step and/or the exception class it throws.');

        $this->journey($this->journeyOf())->knownIssue();
    }

    public function test_an_issue_matches_an_invariant_or_a_step_not_both(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('knownIssue() matches an invariant, or a step and/or the exception class it throws, not both.');

        $this->journey($this->journeyOf())->knownIssue('balanced', throwing: LogicException::class);
    }

    public function test_an_invariant_with_a_step_is_rejected_too(): void
    {
        $this->expectException(InvalidJourneyException::class);

        $this->journey($this->journeyOf())->knownIssue('balanced', step: 'first');
    }

    /**
     * "first" breaks the "balanced" invariant when $breaks; the second step
     * breaks "also balanced" when $alsoBroken, and throws a LogicException
     * when $throws.
     */
    private function journeyOf(bool $breaks = false, bool $throws = false, bool $alsoBroken = false, string $second = 'second'): Journey
    {
        $countCheck = function (): void {
            $this->checks++;
        };

        return new class($breaks, $throws, $alsoBroken, $second, $countCheck) extends Journey
        {
            /** @param Closure(): void $countCheck */
            public function __construct(
                private readonly bool $breaks,
                private readonly bool $throws,
                private readonly bool $alsoBroken,
                private readonly string $second,
                private readonly Closure $countCheck,
            ) {}

            public function steps(): array
            {
                return [
                    Step::make('first')->act(fn (Context $ctx): mixed => $ctx->remember('broken', $this->breaks)),
                    Step::make($this->second)->act(function (Context $ctx): void {
                        $ctx->remember('also broken', $this->alsoBroken);

                        if ($this->throws) {
                            throw new LogicException('second is broken');
                        }
                    }),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('balanced', function (Context $ctx): void {
                        ($this->countCheck)();

                        if ($ctx->get('broken') === true) {
                            throw new RuntimeException('unbalanced');
                        }
                    }),
                    Invariant::make('also balanced', function (Context $ctx): void {
                        if ($ctx->get('also broken') === true) {
                            throw new RuntimeException('also unbalanced');
                        }
                    }),
                ];
            }
        };
    }

    /** @param Closure(): void $run */
    private function failureOf(Closure $run): JourneyFailedException
    {
        try {
            $run();
        } catch (JourneyFailedException $failure) {
            $this->addToAssertionCount(1);

            return $failure;
        } catch (SkippedWithMessageException $skipped) {
            $this->fail('Expected the run to fail, but it was skipped: '.$skipped->getMessage());
        }

        $this->fail('Expected the run to fail.');
    }

    /** @param Closure(): void $run */
    private function skipMessage(Closure $run): string
    {
        try {
            $run();
        } catch (SkippedWithMessageException $skipped) {
            return $skipped->getMessage();
        }

        $this->fail('Expected the run to be skipped.');
    }

    /** @param Closure(): void $fn */
    private function captureStderr(Closure $fn): string
    {
        static $registered = false;

        if (! $registered) {
            stream_filter_register('known-issues-capture', KnownIssuesStderrFilter::class);
            $registered = true;
        }

        KnownIssuesStderrFilter::$buffer = '';
        $handle = stream_filter_append(STDERR, 'known-issues-capture');

        try {
            $fn();
        } finally {
            if ($handle !== false) {
                stream_filter_remove($handle);
            }
        }

        return KnownIssuesStderrFilter::$buffer;
    }
}
