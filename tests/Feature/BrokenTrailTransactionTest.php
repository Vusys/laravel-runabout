<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Support\RollBack;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * On MySQL and MariaDB a schema change inside a step commits implicitly,
 * ending the trail's transaction. The step that did it fails with an
 * explanation, instead of the reset failing afterwards with a bare driver
 * error. SQLite's DDL is transactional, so the tests end the transaction
 * the way an implicit commit would: a raw PDO commit.
 */
final class BrokenTrailTransactionTest extends TestCase
{
    use RunsJourneys;

    public function test_the_step_that_ends_the_trail_transaction_fails_with_an_explanation(): void
    {
        $failure = $this->failureOf($this->journey($this->committingJourney()));

        $this->assertInstanceOf(InvalidJourneyException::class, $failure->getPrevious());
        $this->assertSame(
            'Step "commit behind the trail\'s back" ended the trail\'s database transaction on connection "testing" (an implicit commit, usually a schema change such as Schema::table() on MySQL or MariaDB). The rows this trail wrote can no longer be rolled back, so later trails would see them. Use resetByTruncating(...) for journeys that run schema changes, or move the change out of the step.',
            $failure->getPrevious()->getMessage(),
        );
        $this->assertSame(['create user', 'commit behind the trail\'s back'], $failure->trail()->steps());
    }

    public function test_a_step_that_commits_the_trails_level_away_fails_even_while_a_transaction_is_open(): void
    {
        $failure = $this->failureOf($this->journey(new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('commit one level too many')->act(function (): void {
                        DB::commit();
                        DB::connection()->getPdo()->beginTransaction();
                    }),
                ];
            }
        }));

        $this->assertStringStartsWith('Step "commit one level too many" ended the trail\'s database transaction', $failure->getPrevious()?->getMessage() ?? '');
        $this->resyncTransactions();
    }

    public function test_interleaved_journeys_are_watched_too(): void
    {
        $failure = $this->failureOf($this->interleave($this->committingJourney(), $this->committingJourney()));

        $this->assertStringContainsString('ended the trail\'s database transaction on connection "testing"', $failure->getMessage());
    }

    public function test_reset_connections_is_watched_too(): void
    {
        $failure = $this->failureOf($this->journey($this->committingJourney())->resetConnections('testing'));

        $this->assertStringContainsString('ended the trail\'s database transaction on connection "testing"', $failure->getMessage());
    }

    public function test_a_custom_reset_is_not_watched(): void
    {
        $ran = false;

        $this->journey($this->committingJourney())
            ->resetWith(function (Closure $trail) use (&$ran): void {
                DB::beginTransaction();
                $trail();
                $ran = true;
            })
            ->shuffles(0)
            ->run();

        $this->assertTrue($ran);
        $this->resyncTransactions();
    }

    public function test_a_step_that_commits_its_own_nested_transaction_is_fine(): void
    {
        $this->journey(new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('nested transaction')->act(fn () => DB::transaction(fn () => User::query()->create(['name' => 'ana']))),
                ];
            }
        })->shuffles(0)->run();

        $this->assertSame(0, User::query()->count());
    }

    public function test_a_failing_rollback_does_not_mask_the_trails_own_failure(): void
    {
        $this->breakNestedTransaction();

        try {
            RollBack::after(fn () => throw new RuntimeException('the trail failed'), [DB::connection()]);
            $this->fail('Expected the trail failure.');
        } catch (RuntimeException $caught) {
            $this->assertSame('the trail failed', $caught->getMessage());
        } finally {
            $this->resyncTransactions();
        }
    }

    public function test_a_failing_rollback_on_its_own_is_explained(): void
    {
        $this->breakNestedTransaction();

        try {
            RollBack::after(fn (): null => null, [DB::connection()]);
            $this->fail('Expected the rollback failure.');
        } catch (RuntimeException $caught) {
            $this->assertStringStartsWith('Rolling back the trail\'s transaction on connection "testing" failed: ', $caught->getMessage());
            $this->assertStringEndsWith(' Something in the trail ended the transaction (an implicit commit, usually a schema change on MySQL or MariaDB), so its rows were not rolled back. Use resetByTruncating(...) for journeys that run schema changes.', $caught->getMessage());
            $this->assertNotNull($caught->getPrevious());
            $this->assertSame(0, $caught->getCode());
        } finally {
            $this->resyncTransactions();
        }
    }

    /** A savepoint whose transaction was committed underneath it, so rolling back to it fails. */
    private function breakNestedTransaction(): void
    {
        DB::beginTransaction();
        DB::beginTransaction();
        DB::connection()->getPdo()->commit();
    }

    /** Bring Laravel's transaction count back in line with the PDO after a deliberate break. */
    private function resyncTransactions(): void
    {
        $pdo = DB::connection()->getPdo();

        if (! $pdo->inTransaction()) {
            $pdo->beginTransaction();
        }

        DB::connection()->rollBack(0);
    }

    private function committingJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('create user')->act(fn (Context $ctx): mixed => $ctx->remember('user', User::query()->create(['name' => 'ana']))),
                    Step::make('commit behind the trail\'s back')->after('create user')->act(function (): void {
                        if (DB::connection()->getPdo()->inTransaction()) {
                            DB::connection()->getPdo()->commit();
                        }
                    }),
                ];
            }
        };
    }

    private function failureOf(PendingJourney $journey): JourneyFailedException
    {
        try {
            $journey->shuffles(0)->run();
        } catch (JourneyFailedException $failure) {
            return $failure;
        }

        $this->fail('The journey was expected to fail.');
    }
}
