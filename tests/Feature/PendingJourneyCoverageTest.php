<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Database\Events\TransactionRolledBack;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use RuntimeException;
use Throwable;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;
use Vusys\Runabout\Trail;

/**
 * A stream filter that intercepts bytes written to STDERR so verbose-mode
 * output (fwrite(STDERR, ...) in PendingJourney::run()/registerVerbosePrinter())
 * can be asserted on. It deliberately swallows the bytes rather than passing
 * them on to the real stream: re-emitting captured output through a piped
 * STDERR (as Infection's test-runner process uses) reliably deadlocks the
 * child process, so capture-only is both simpler and safer here.
 */
final class PendingJourneyCoverageStderrFilter extends \php_user_filter
{
    public static string $buffer = '';

    /**
     * @param  resource  $in
     * @param  resource  $out
     */
    public function filter($in, $out, &$consumed, $closing): int
    {
        while ($bucket = stream_bucket_make_writeable($in)) {
            self::$buffer .= $bucket->data;
            // strlen() (always int) rather than ->datalen: the latter's
            // reflected type varies by PHP version, tripping the by-ref
            // int contract on 8.3 while a cast gets stripped as redundant
            // on 8.4.
            $consumed += strlen($bucket->data);
        }

        return PSFS_PASS_ON;
    }
}

/** A mutable counter a fixture journey increments; the wrapper resets it per trial, like a rolled-back transaction would reset real state. */
final class PendingJourneyCoverageCounter
{
    public int $n = 0;
}

/**
 * Targets the 108 mutants Infection found escaping in src/PendingJourney.php.
 * Each test pins the exact observable effect of one mutation: a boundary, a
 * default value, a method call's side effect, an env flag's behavioural
 * branch, or an exact shrink/replay count. See docs/shrinking-design.md and
 * docs/value-shrinking-design.md for the algorithms being pinned.
 *
 * Conventions:
 *  - `journeyOf()`/`pending()` build a bare PendingJourney with a no-op
 *    wrapper (as ExecutionModesTest does) for logic that needs no database.
 *  - `PendingJourneyCoverageCounter` + a resetting wrapper stand in for a
 *    rolled-back transaction in fixtures that need per-trial state without a
 *    real database (the shrinker's own resets are the thing under test, not
 *    ours).
 *  - `captureStderr()` intercepts RUNABOUT_VERBOSE/RUNABOUT_COVERAGE output.
 */
final class PendingJourneyCoverageTest extends TestCase
{
    use RunsJourneys;

    /** @param Application $app */
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    // =====================================================================
    // repeatHeavy(): default bias and its minimum-1 clamp
    // =====================================================================

    public function test_repeat_heavy_default_bias_is_five(): void
    {
        $journey = $this->repeatableJourney();

        foreach ([1, 2, 3, 4, 5] as $seed) {
            $expected = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: 5)->steps();

            $actual = null;
            $this->pending($journey)->repeatHeavy()->seed($seed)->onTrail(function (Trail $t) use (&$actual): void {
                $actual = $t->steps();
            })->run();

            $this->assertSame($expected, $actual, "repeatHeavy() with no args must behave exactly like an explicit bias of 5 (seed {$seed}).");
        }

        // Guard against a vacuous assertion: bias really does change the trail somewhere in this seed range.
        $differs = false;
        foreach ([1, 2, 3, 4, 5] as $seed) {
            $five = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: 5)->steps();
            $four = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: 4)->steps();
            $differs = $differs || $five !== $four;
        }
        $this->assertTrue($differs, 'bias 5 vs bias 4 must diverge for at least one seed, or the assertion above proves nothing.');
    }

    public function test_repeat_heavy_clamps_a_non_positive_bias_up_to_one(): void
    {
        $journey = $this->repeatableJourney();

        foreach ([1, 2, 3] as $seed) {
            $expectedAtOne = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: 1)->steps();

            $actual = null;
            $this->pending($journey)->repeatHeavy(0)->seed($seed)->onTrail(function (Trail $t) use (&$actual): void {
                $actual = $t->steps();
            })->run();

            $this->assertSame($expectedAtOne, $actual, "repeatHeavy(0) must clamp to bias 1, not 0 or 2 (seed {$seed}).");
        }
    }

    public function test_repeat_heavy_bias_of_one_stays_one(): void
    {
        $journey = $this->repeatableJourney();

        foreach ([1, 2, 3] as $seed) {
            $expectedAtOne = (new JourneyRunner)->run($journey, $seed, shuffle: true, repeatBias: 1)->steps();

            $actual = null;
            $this->pending($journey)->repeatHeavy(1)->seed($seed)->onTrail(function (Trail $t) use (&$actual): void {
                $actual = $t->steps();
            })->run();

            $this->assertSame($expectedAtOne, $actual, "repeatHeavy(1) must stay bias 1, not clamp up to 2 (seed {$seed}).");
        }
    }

    private function repeatableJourney(): Journey
    {
        return $this->journeyOf([
            Step::make('again')->repeatable(max: 20)->weight(1),
            Step::make('one'),
            Step::make('two'),
        ]);
    }

    // =====================================================================
    // resetWith(): must stay a public extension point
    // =====================================================================

    public function test_reset_with_is_callable_from_outside(): void
    {
        $pending = new PendingJourney($this->journeyOf([Step::make('a')]), fn (Closure $trail) => $trail());

        $result = $pending->resetWith(fn (Closure $trail) => $trail());

        $this->assertSame($pending, $result, 'resetWith() must be public and fluent.');
    }

    // =====================================================================
    // resetByTruncating(): runs the trail, disables FKs, truncates, re-enables
    // =====================================================================

    public function test_reset_by_truncating_runs_the_trail(): void
    {
        $ran = false;
        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx) use (&$ran): void {
                $ran = true;
            }),
        ]);

        $this->journey($journey)->resetByTruncating('communities', 'posts')->run();

        $this->assertTrue($ran, 'resetByTruncating() must still run the wrapped trail.');
    }

    public function test_reset_by_truncating_cleans_up_even_when_the_trail_throws(): void
    {
        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx): never {
                Community::query()->create(['name' => 'c']);
                throw new RuntimeException('boom');
            }),
        ]);

        try {
            $this->journey($journey)->resetByTruncating('communities')->shuffles(0)->run();
            $this->fail('expected the trail to fail');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(0, Community::query()->count(), 'truncation must still run even though the trail threw.');
    }

    public function test_reset_by_truncating_disables_foreign_keys_so_a_referenced_table_can_be_truncated(): void
    {
        DB::connection()->getSchemaBuilder()->enableForeignKeyConstraints();

        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx): void {
                $community = Community::query()->create(['name' => 'c']);
                Post::query()->create(['community_id' => $community->id, 'title' => 't']);
            }),
        ]);

        // Truncating only 'communities' (not 'posts') while a post still
        // references it succeeds only because FKs are disabled first.
        $this->journey($journey)->resetByTruncating('communities')->shuffles(0)->run();

        $this->assertSame(0, Community::query()->count());
    }

    public function test_reset_by_truncating_reenables_foreign_keys_even_after_a_failed_truncate(): void
    {
        DB::connection()->getSchemaBuilder()->enableForeignKeyConstraints();

        $journey = $this->journeyOf([Step::make('noop')]);

        try {
            // Truncating a nonexistent table makes the truncate loop throw
            // partway through.
            $this->journey($journey)->resetByTruncating('does_not_exist')->shuffles(0)->run();
            $this->fail('expected the truncate to throw');
        } catch (Throwable) {
            // expected — the underlying DB error, not a JourneyFailedException
        }

        $this->assertSame(1, $this->foreignKeysEnabled(), 'FKs must be re-enabled even though truncate() threw.');
    }

    public function test_reset_by_truncating_reenables_foreign_keys_after_a_successful_truncate(): void
    {
        DB::connection()->getSchemaBuilder()->enableForeignKeyConstraints();

        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx): void {
                Community::query()->create(['name' => 'c']);
            }),
        ]);

        $this->journey($journey)->resetByTruncating('communities')->shuffles(0)->run();

        $this->assertSame(1, $this->foreignKeysEnabled());
    }

    // =====================================================================
    // resetConnections(): no-args transacts the default connection alone
    // =====================================================================

    public function test_reset_connections_with_no_args_still_transacts_the_default_connection(): void
    {
        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx): void {
                User::query()->create(['name' => 'x']);
            }),
        ]);

        $this->journey($journey)->resetConnections()->shuffles(3)->run();

        $this->assertSame(0, User::query()->count(), 'resetConnections() with no args must still roll back the default connection.');
    }

    // =====================================================================
    // rebuildTrailReset(): runs the trail, rolls back in reverse, then cleans up externally — even on failure
    // =====================================================================

    public function test_rebuild_trail_reset_runs_the_trail(): void
    {
        $ran = false;
        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx) use (&$ran): void {
                $ran = true;
            }),
        ]);

        $this->journey($journey)->resetConnections('sqlite')->shuffles(0)->run();

        $this->assertTrue($ran);
    }

    public function test_rebuild_trail_reset_cleans_up_even_when_the_trail_throws(): void
    {
        $cleanups = 0;

        $journey = $this->journeyOf([
            Step::make('write')->act(function (Context $ctx): never {
                User::query()->create(['name' => 'x']);
                throw new RuntimeException('boom');
            }),
        ]);

        try {
            $this->journey($journey)
                ->resetExternal(function () use (&$cleanups): void {
                    $cleanups++;
                })
                ->shuffles(0)
                ->run();
            $this->fail('expected failure');
        } catch (Throwable) {
            // expected
        }

        $this->assertSame(0, User::query()->count(), 'the connection must roll back even though the trail threw.');
        $this->assertSame(1, $cleanups, 'external cleanup must run even though the trail threw.');
    }

    public function test_rebuild_trail_reset_rolls_back_connections_in_reverse_order(): void
    {
        $order = [];
        Event::listen(TransactionRolledBack::class, function (TransactionRolledBack $event) use (&$order): void {
            $order[] = $event->connectionName;
        });

        $journey = $this->journeyOf([Step::make('noop')]);

        $this->journey($journey)->resetConnections('sqlite', 'secondary')->shuffles(0)->run();

        $this->assertSame(['secondary', 'sqlite'], $order, 'connections must roll back in the reverse of the order they were opened.');
    }

    // =====================================================================
    // execute(): dispatch between exhaustive / artifact / seed / shuffles,
    // and the exact "total" each mode registers for RUNABOUT_VERBOSE.
    // =====================================================================

    public function test_exhaustive_mode_prints_a_bare_count_with_no_total_when_verbose(): void
    {
        putenv('RUNABOUT_VERBOSE=1');
        try {
            $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
            $output = $this->captureStderr(function () use ($journey): void {
                $this->pending($journey)->exhaustive()->run();
            });
        } finally {
            putenv('RUNABOUT_VERBOSE');
        }

        // Exhaustive mode's total is unknown up front (registerVerbosePrinter(total: null)):
        // the printed count has no "/N" denominator, and a call must have
        // happened at all (removing it entirely leaves this empty).
        $this->assertStringContainsString('trail 1 (exhaustive', $output);
        $this->assertStringContainsString('trail 2 (exhaustive', $output);
        $this->assertStringNotContainsString('1/', $output);
    }

    public function test_trail_artifact_takes_priority_over_the_env_var(): void
    {
        $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);

        putenv('RUNABOUT_TRAIL='.json_encode(['seed' => 999, 'steps' => [[null, 'a', 1], [null, 'b', 1]]]));
        try {
            $seed = null;
            $this->pending($journey)
                ->trail(['seed' => 123, 'steps' => [[null, 'a', 1], [null, 'b', 1]]])
                ->onTrail(function (Trail $t) use (&$seed): void {
                    $seed = $t->seed();
                })
                ->run();

            $this->assertSame(123, $seed, '->trail() must win over RUNABOUT_TRAIL, not the other way round.');
        } finally {
            putenv('RUNABOUT_TRAIL');
        }
    }

    public function test_replay_mode_prints_a_total_of_one_when_verbose(): void
    {
        putenv('RUNABOUT_VERBOSE=1');
        try {
            $journey = $this->journeyOf([Step::make('a')]);
            $output = $this->captureStderr(function () use ($journey): void {
                $this->pending($journey)->trail(['seed' => 1, 'steps' => [[null, 'a', 1]]])->run();
            });
        } finally {
            putenv('RUNABOUT_VERBOSE');
        }

        $this->assertStringContainsString('trail 1/1', $output);
    }

    public function test_seed_method_takes_priority_over_the_env_var(): void
    {
        $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);

        putenv('RUNABOUT_SEED=999');
        try {
            $seed = null;
            $this->pending($journey)
                ->seed(123)
                ->onTrail(function (Trail $t) use (&$seed): void {
                    $seed = $t->seed();
                })
                ->run();

            $this->assertSame(123, $seed, '->seed() must win over RUNABOUT_SEED, not the other way round.');
        } finally {
            putenv('RUNABOUT_SEED');
        }
    }

    public function test_seed_mode_prints_a_total_of_one_when_verbose(): void
    {
        putenv('RUNABOUT_VERBOSE=1');
        try {
            $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
            $output = $this->captureStderr(function () use ($journey): void {
                $this->pending($journey)->seed(1)->run();
            });
        } finally {
            putenv('RUNABOUT_VERBOSE');
        }

        $this->assertStringContainsString('trail 1/1', $output);
    }

    public function test_default_mode_prints_a_total_of_one_plus_shuffles_when_verbose(): void
    {
        putenv('RUNABOUT_VERBOSE=1');
        try {
            $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
            $output = $this->captureStderr(function () use ($journey): void {
                $this->pending($journey)->shuffles(3)->run();
            });
        } finally {
            putenv('RUNABOUT_VERBOSE');
        }

        // Canonical + 3 shuffles = a total of 4 — not 3 (shuffles alone), not
        // 5, not a negative number, and the call must have happened at all.
        $this->assertStringContainsString('trail 1/4', $output);
        $this->assertStringContainsString('trail 4/4', $output);

        // Each printed trail is described with describe(markLast: false):
        // no line carries the ">" marker that points at a failing step.
        $this->assertStringNotContainsString('>', $output);
    }

    public function test_the_canonical_trail_derives_its_seed_from_the_journey_class_and_index_zero(): void
    {
        $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
        $expected = crc32($journey::class.'#0');

        $seed = null;
        $this->pending($journey)->onTrail(function (Trail $t) use (&$seed): void {
            if ($t->mode() === 'canonical') {
                $seed = $t->seed();
            }
        })->run();

        $this->assertSame($expected, $seed);
    }

    // =====================================================================
    // shrink(): boundary and combinator behaviour, with no draws involved
    // =====================================================================

    private function counterWrapper(PendingJourneyCoverageCounter $counter): Closure
    {
        return function (Closure $trail) use ($counter): void {
            $counter->n = 0;
            $trail();
        };
    }

    private function onlyIncJourney(PendingJourneyCoverageCounter $counter, int $minRuns, int $threshold): Journey
    {
        return new class($counter, $minRuns, $threshold) extends Journey
        {
            public function __construct(
                private readonly PendingJourneyCoverageCounter $counter,
                private readonly int $minRuns,
                private readonly int $threshold,
            ) {}

            public function steps(): array
            {
                $counter = $this->counter;
                $threshold = $this->threshold;

                return [
                    Step::make('inc')->repeatable(max: 20, min: $this->minRuns)->act(function (Context $ctx) use ($counter, $threshold): void {
                        $counter->n++;
                        if ($counter->n >= $threshold) {
                            throw new RuntimeException('boom at '.$counter->n);
                        }
                    }),
                ];
            }
        };
    }

    private function noiseAndIncJourney(PendingJourneyCoverageCounter $counter, int $incMin, int $threshold, int $noiseWeight): Journey
    {
        return new class($counter, $incMin, $threshold, $noiseWeight) extends Journey
        {
            public function __construct(
                private readonly PendingJourneyCoverageCounter $counter,
                private readonly int $incMin,
                private readonly int $threshold,
                private readonly int $noiseWeight,
            ) {}

            public function steps(): array
            {
                $counter = $this->counter;
                $threshold = $this->threshold;

                return [
                    Step::make('noise')->repeatable(max: 30)->weight($this->noiseWeight),
                    Step::make('inc')->repeatable(max: 20, min: $this->incMin)->weight(1)->act(function (Context $ctx) use ($counter, $threshold): void {
                        $counter->n++;
                        if ($counter->n >= $threshold) {
                            throw new RuntimeException('boom at '.$counter->n);
                        }
                    }),
                ];
            }
        };
    }

    public function test_shrinking_is_a_no_op_on_an_already_minimal_trail_with_no_draws(): void
    {
        // A single repeatable step whose failure fires exactly on its 3rd
        // run: fewer than 3 runs never reproduces, so the trail is already
        // length-minimal, and it draws nothing, so there is nothing to value
        // shrink either. shrink() must return the original failure verbatim.
        $counter = new PendingJourneyCoverageCounter;
        $journey = $this->onlyIncJourney($counter, minRuns: 10, threshold: 3);
        $pending = new PendingJourney($journey, $this->counterWrapper($counter));

        try {
            $pending->seed(1)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertStringNotContainsString('Shrunk from', $e->getMessage());
            $this->assertSame(['inc', 'inc', 'inc'], $e->trail()->steps());
        }
    }

    public function test_shrinking_removes_padding_and_reports_the_exact_replay_count(): void
    {
        $counter = new PendingJourneyCoverageCounter;
        $journey = $this->noiseAndIncJourney($counter, incMin: 3, threshold: 3, noiseWeight: 5);
        $pending = new PendingJourney($journey, $this->counterWrapper($counter));

        try {
            $pending->seed(1)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Shrunk from 14 executions to 3 (32 replays)', $e->getMessage());
            $this->assertSame(['inc', 'inc', 'inc'], $e->trail()->steps());
        }
    }

    public function test_shrinking_is_skipped_entirely_when_runabout_shrink_is_zero(): void
    {
        putenv('RUNABOUT_SHRINK=0');
        try {
            $counter = new PendingJourneyCoverageCounter;
            $journey = $this->noiseAndIncJourney($counter, incMin: 3, threshold: 3, noiseWeight: 5);
            $pending = new PendingJourney($journey, $this->counterWrapper($counter));

            try {
                $pending->seed(1)->run();
                $this->fail('expected failure');
            } catch (JourneyFailedException $e) {
                $this->assertStringNotContainsString('Shrunk from', $e->getMessage(), 'RUNABOUT_SHRINK=0 must disable shrinking even on an otherwise-shrinkable shuffled trail.');
            }
        } finally {
            putenv('RUNABOUT_SHRINK');
        }
    }

    private function drawThenAssertJourney(int $min, int $max): Journey
    {
        return new class($min, $max) extends Journey
        {
            public function __construct(private readonly int $min, private readonly int $max) {}

            public function steps(): array
            {
                return [
                    Step::make('draw')->act(function (Context $ctx): void {
                        $ctx->randomInt($this->min, $this->max);
                    }),
                    Step::make('assert')->after('draw')->act(function (Context $ctx): never {
                        throw new RuntimeException('boom');
                    }),
                ];
            }
        };
    }

    private function drawThenAssertPending(int $min, int $max): PendingJourney
    {
        return new PendingJourney($this->drawThenAssertJourney($min, $max), fn (Closure $trail) => $trail());
    }

    public function test_a_draw_already_at_its_minimum_is_not_pinned_in_the_artifact(): void
    {
        // The draw's domain is a single value (1..1): it is always already at
        // its minimum, so value shrinking changes nothing and the whole trail
        // is already length-minimal (both steps are mandatory) — shrink()
        // must return the original failure verbatim, with no "Shrunk from".
        try {
            $this->drawThenAssertPending(1, 1)->seed(1)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertStringNotContainsString('Shrunk from', $e->getMessage());
        }
    }

    public function test_a_draw_shrinks_toward_its_minimum_and_reports_the_exact_replay_count(): void
    {
        try {
            $this->drawThenAssertPending(1, 100)->seed(1)->run();
            $this->fail('expected failure');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('Shrunk from 2 executions to 2 (12 replays)', $e->getMessage());

            $steps = $e->trail()->artifact()['steps'];
            $draw = $steps[0];
            $this->assertArrayHasKey(3, $draw, 'the pinned draw must appear in the artifact.');
            $this->assertSame([1], $draw[3] ?? null, 'the draw must shrink all the way to its floor (nothing about the failure depends on its value).');
        }
    }

    // =====================================================================
    // shrinkBudget(): RUNABOUT_SHRINK_BUDGET parsing
    // =====================================================================

    private function shrunkLength(?string $budget): int
    {
        putenv($budget === null ? 'RUNABOUT_SHRINK_BUDGET' : 'RUNABOUT_SHRINK_BUDGET='.$budget);
        try {
            $counter = new PendingJourneyCoverageCounter;
            $journey = $this->noiseAndIncJourney($counter, incMin: 3, threshold: 3, noiseWeight: 5);
            $pending = new PendingJourney($journey, $this->counterWrapper($counter));

            try {
                $pending->seed(1)->run();
                $this->fail('expected failure');
            } catch (JourneyFailedException $e) {
                return count($e->trail()->steps());
            }
        } finally {
            putenv('RUNABOUT_SHRINK_BUDGET');
        }
    }

    public function test_shrink_budget_defaults_to_two_hundred_and_fully_reduces(): void
    {
        $this->assertSame(3, $this->shrunkLength(null));
    }

    public function test_shrink_budget_of_zero_is_invalid_and_falls_back_to_the_default(): void
    {
        $this->assertSame(3, $this->shrunkLength('0'));
    }

    public function test_shrink_budget_with_trailing_non_digits_is_invalid_and_falls_back_to_the_default(): void
    {
        $this->assertSame(3, $this->shrunkLength('5abc'));
    }

    public function test_shrink_budget_of_five_is_honoured_and_leaves_the_trail_unfinished(): void
    {
        $this->assertGreaterThan(3, $this->shrunkLength('5'));
    }

    // =====================================================================
    // coverageCollector() / registerVerbosePrinter(): off unless the env is set
    // =====================================================================

    public function test_coverage_is_off_by_default(): void
    {
        $journey = $this->journeyOf([Step::make('a')]);
        $output = $this->captureStderr(function () use ($journey): void {
            $this->pending($journey)->shuffles(0)->run();
        });

        $this->assertStringNotContainsString('trail coverage', $output);
    }

    public function test_verbose_is_off_by_default(): void
    {
        $journey = $this->journeyOf([Step::make('a')]);
        $output = $this->captureStderr(function () use ($journey): void {
            $this->pending($journey)->shuffles(0)->run();
        });

        $this->assertSame('', $output);
    }

    // =====================================================================
    // seedFromEnvironment() / artifactFromEnvironment(): RUNABOUT_SEED / RUNABOUT_TRAIL parsing
    // =====================================================================

    public function test_seed_env_of_empty_string_is_ignored(): void
    {
        putenv('RUNABOUT_SEED=');
        try {
            $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
            $mode = null;
            $this->pending($journey)->onTrail(function (Trail $t) use (&$mode): void {
                $mode = $t->mode();
            })->shuffles(0)->run();

            $this->assertSame('canonical', $mode, 'an empty RUNABOUT_SEED must not be treated as a seed override.');
        } finally {
            putenv('RUNABOUT_SEED');
        }
    }

    public function test_seed_env_of_zero_is_a_valid_seed(): void
    {
        putenv('RUNABOUT_SEED=0');
        try {
            $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
            $seed = null;
            $this->pending($journey)->onTrail(function (Trail $t) use (&$seed): void {
                $seed = $t->seed();
            })->run();

            $this->assertSame(0, $seed, 'RUNABOUT_SEED=0 must be honoured as seed 0, not treated as unset.');
        } finally {
            putenv('RUNABOUT_SEED');
        }
    }

    public function test_trail_env_of_empty_string_is_ignored(): void
    {
        putenv('RUNABOUT_TRAIL=');
        try {
            $journey = $this->journeyOf([Step::make('a')]);
            $mode = null;
            $this->pending($journey)->onTrail(function (Trail $t) use (&$mode): void {
                $mode = $t->mode();
            })->shuffles(0)->run();

            $this->assertSame('canonical', $mode, 'an empty RUNABOUT_TRAIL must not be treated as an artifact.');
        } finally {
            putenv('RUNABOUT_TRAIL');
        }
    }

    public function test_trail_env_of_valid_json_is_used(): void
    {
        putenv('RUNABOUT_TRAIL='.json_encode(['seed' => 1, 'steps' => [[null, 'a', 1]]]));
        try {
            $journey = $this->journeyOf([Step::make('a')]);
            $mode = null;
            $this->pending($journey)->onTrail(function (Trail $t) use (&$mode): void {
                $mode = $t->mode();
            })->run();

            $this->assertSame('replayed', $mode, 'a valid RUNABOUT_TRAIL must be decoded and replayed.');
        } finally {
            putenv('RUNABOUT_TRAIL');
        }
    }

    // =====================================================================
    // replay(): a non-viable artifact is re-thrown with exception code 0
    // =====================================================================

    public function test_a_non_viable_replay_exception_carries_code_zero(): void
    {
        $journey = $this->journeyOf([Step::make('a')->after('b'), Step::make('b')]);

        try {
            $this->pending($journey)->trail(['seed' => 1, 'steps' => [[null, 'a', 1]]])->run();
            $this->fail('expected a not-viable exception');
        } catch (InvalidJourneyException $e) {
            $this->assertSame(0, $e->getCode());
        }
    }

    // =====================================================================
    // runExhaustive(): the ordering-count overflow guard
    // =====================================================================

    public function test_exhaustive_ordering_count_at_the_limit_boundary_succeeds(): void
    {
        // 3! = 6 orderings, exactly at the limit: "more than" the limit must
        // not include equal-to-the-limit.
        $journey = $this->journeyOf([Step::make('a'), Step::make('b'), Step::make('c')]);

        $this->pending($journey)->exhaustive(limit: 6)->run();

        $this->addToAssertionCount(1);
    }

    public function test_exhaustive_ordering_count_over_the_limit_throws(): void
    {
        // 3! = 6 orderings, over a limit of 4.
        $journey = $this->journeyOf([Step::make('a'), Step::make('b'), Step::make('c')]);

        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('more than 4 orderings');

        $this->pending($journey)->exhaustive(limit: 4)->run();
    }

    public function test_exhaustive_ordering_count_loop_never_runs_for_a_single_step(): void
    {
        // A single step never enters the "for ($i = 2; ...)" loop at all
        // (count = 1), so even limit 0 must not be flagged as exceeded.
        $journey = $this->journeyOf([Step::make('a')]);

        $this->pending($journey)->exhaustive(limit: 0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_exhaustive_seed_indices_increase_across_orderings(): void
    {
        $journey = $this->journeyOf([Step::make('a'), Step::make('b')]);
        $expected = [crc32($journey::class.'#0'), crc32($journey::class.'#1')];

        $seeds = [];
        $this->pending($journey)->exhaustive()->onTrail(function (Trail $t) use (&$seeds): void {
            $seeds[] = $t->seed();
        })->run();

        $this->assertSame($expected, $seeds);
    }

    // =====================================================================
    // permutations(): the base case for a single remaining item
    // =====================================================================

    public function test_exhaustive_mode_runs_a_single_step_journey_exactly_once(): void
    {
        $journey = $this->journeyOf([Step::make('a')]);

        $count = 0;
        $this->pending($journey)->exhaustive()->onTrail(function () use (&$count): void {
            $count++;
        })->run();

        $this->assertSame(1, $count);
    }

    // =====================================================================
    // helpers
    // =====================================================================

    private function captureStderr(Closure $fn): string
    {
        static $registered = false;

        if (! $registered) {
            stream_filter_register('pending-journey-coverage-capture', PendingJourneyCoverageStderrFilter::class);
            $registered = true;
        }

        PendingJourneyCoverageStderrFilter::$buffer = '';
        $handle = stream_filter_append(STDERR, 'pending-journey-coverage-capture');

        try {
            $fn();
        } finally {
            if ($handle !== false) {
                stream_filter_remove($handle);
            }
        }

        return PendingJourneyCoverageStderrFilter::$buffer;
    }

    /** The current value of SQLite's foreign_keys pragma (1 enabled, 0 disabled). */
    private function foreignKeysEnabled(): int
    {
        $pragma = (array) DB::selectOne('PRAGMA foreign_keys');
        $value = $pragma['foreign_keys'] ?? 0;

        return is_numeric($value) ? (int) $value : 0;
    }

    /** @param list<Step> $steps */
    private function journeyOf(array $steps): Journey
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

    private function pending(Journey $journey): PendingJourney
    {
        return new PendingJourney($journey, fn (Closure $trail) => $trail());
    }
}
