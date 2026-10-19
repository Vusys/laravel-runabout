<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Database\Connection;
use Illuminate\Database\Connectors\SQLiteConnector;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Randomness\Draw;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\LockingSqliteConnection;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;
use Vusys\Runabout\Trail;

/**
 * racing() runs a competitor at an unlocked read of a table, inside the
 * request's check-then-act window, on a connection that writes lock clauses.
 */
final class RacingTest extends TestCase
{
    use RunsJourneys;

    /** @var list<int> For each competitor run, how many unlocked reads of users the request had made. */
    private array $racedAt = [];

    private int $reads = 0;

    /** @var list<int> How many times the competitor ran in each request. */
    private array $perRequest = [];

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app->bind('db.connector.racing', SQLiteConnector::class);
        Connection::resolverFor('racing', fn ($pdo, string $database, string $prefix, array $config): Connection => new LockingSqliteConnection($pdo, $database, $prefix, $config));

        $app['config']->set('database.connections.racing', ['driver' => 'racing', 'database' => ':memory:', 'prefix' => '', 'foreign_key_constraints' => false]);
        $app['config']->set('database.default', 'racing');
    }

    public function test_an_unguarded_check_then_insert_is_caught(): void
    {
        try {
            $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(
                table: 'users',
                competitor: $this->competitor('ann'),
                request: fn () => $this->register('ann'),
            )))->shuffles(10)->run();
            $this->fail('Expected the race to create a duplicate.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString('Invariant "User rows are unique by (name)" violated', $failure->getMessage());
            $this->assertStringContainsString('ann', $failure->getMessage());
        }
    }

    public function test_a_locked_read_is_never_raced(): void
    {
        $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'users',
            competitor: $this->competitor('ann'),
            request: function (): void {
                // SQLite's grammar drops lock clauses, so write the locked read out.
                DB::connection()->getEventDispatcher()?->dispatch(new QueryExecuted('select * from "users" where "name" = ? for update', ['ann'], 0.1, DB::connection()));
                User::query()->create(['name' => 'ann']);
            },
        )))->shuffles(10)->run();

        $this->assertSame([], $this->racedAt);
    }

    public function test_reads_of_other_tables_are_never_raced(): void
    {
        $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'posts',
            competitor: $this->competitor('ann'),
            request: fn () => $this->register('ann'),
        )))->shuffles(10)->run();

        $this->assertSame([], $this->racedAt);
    }

    public function test_the_competitor_runs_at_most_once_per_request_and_the_draws_pick_the_read(): void
    {
        $journey = $this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'users',
            competitor: $this->competitor('bob'),
            request: function (): void {
                $this->reads = 0;
                $before = count($this->racedAt);

                foreach (range(1, 3) as $read) {
                    $this->reads++;
                    User::query()->where('name', 'carol')->exists();
                }

                $this->perRequest[] = count($this->racedAt) - $before;
            },
        ), uniqueNames: false);

        $this->journey($journey)->shuffles(40)->run();

        $this->assertNotSame([], $this->perRequest);
        $this->assertSame([0, 1], array_values(array_unique([0, 1, ...$this->perRequest])));
        $this->assertSame([1, 2, 3], array_values(array_unique(array_merge([1, 2, 3], $this->racedAt))));
        $this->assertContains(1, $this->racedAt);
        $this->assertContains(2, $this->racedAt);
    }

    public function test_the_read_raced_replays_with_the_seed(): void
    {
        $journey = $this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'users',
            competitor: $this->competitor('bob'),
            request: function (): void {
                $this->reads = 0;

                foreach (range(1, 4) as $read) {
                    $this->reads++;
                    User::query()->where('name', 'carol')->exists();
                }
            },
        ), uniqueNames: false);

        $runs = [];

        foreach (range(1, 2) as $run) {
            $this->racedAt = [];
            $this->journey($journey)->seed(1234)->run();
            $runs[] = $this->racedAt;
        }

        $this->assertSame($runs[0], $runs[1]);
    }

    public function test_each_unlocked_read_draws_whether_to_interfere_there(): void
    {
        $journey = $this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'users',
            competitor: $this->competitor('bob'),
            request: function (): void {
                $this->reads = 0;

                foreach (range(1, 3) as $read) {
                    $this->reads++;
                    User::query()->where('name', 'carol')->exists();
                }
            },
        ), uniqueNames: false);

        // 0 to 2 interfere at that read, 3 passes it by.
        foreach ([[[0], [1]], [[2], [1]], [[3, 0], [2]], [[3, 3, 1], [3]], [[3, 3, 3], []]] as [$forced, $racedAt]) {
            $this->racedAt = [];
            $draws = [];

            $this->journey($journey)
                ->trail(['seed' => 1, 'steps' => [[null, 'register', 1, $forced]]])
                ->onTrail(function (Trail $trail) use (&$draws): void {
                    $draws = array_map(fn (Draw $draw): array => [$draw->min, $draw->max], $trail->drawsAt(0));
                })
                ->run();

            $this->assertSame($racedAt, $this->racedAt);
            $this->assertSame(array_fill(0, count($forced), [0, 3]), $draws);
        }
    }

    public function test_one_query_listener_serves_every_race(): void
    {
        $listeners = fn (): int => count(resolve('events')->getListeners(QueryExecuted::class));

        $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(table: 'users', competitor: fn (): null => null, request: fn (): null => null)))->shuffles(0)->run();
        $after = $listeners();
        $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(table: 'users', competitor: fn (): null => null, request: fn (): null => null)))->shuffles(3)->run();

        $this->assertSame($after, $listeners());
    }

    public function test_racing_returns_the_requests_result(): void
    {
        $this->journey($this->journeyOf(function (Context $ctx): void {
            $this->assertSame('done', $ctx->racing(table: 'users', competitor: fn (): null => null, request: fn (): string => 'done'));
        }))->shuffles(0)->run();
    }

    public function test_racing_cannot_be_nested(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('racing() can\'t be nested: a step races one request against one competitor at a time.');

        $this->journey($this->journeyOf(fn (Context $ctx) => $ctx->racing(
            table: 'users',
            competitor: fn (): null => null,
            request: fn () => $ctx->racing(table: 'users', competitor: fn (): null => null, request: fn (): null => null),
        )))->shuffles(0)->run();
    }

    public function test_a_failed_request_disarms_the_race(): void
    {
        try {
            $this->journey($this->journeyOf(fn (Context $ctx): mixed => $ctx->racing(
                table: 'users',
                competitor: $this->competitor('ann'),
                request: fn () => throw new RuntimeException('request failed'),
            )))->shuffles(0)->run();
        } catch (JourneyFailedException) {
        }

        // Disarmed: a read outside racing() doesn't run the competitor.
        $this->register('zed');
        $this->assertSame([], $this->racedAt);
    }

    /** The app's code under test: an existence check, then an insert, with no lock between. */
    private function register(string $name): void
    {
        if (! User::query()->where('name', $name)->exists()) {
            User::query()->create(['name' => $name]);
        }
    }

    /** A competitor that registers $name, noting how many reads into the request it ran. */
    private function competitor(string $name): Closure
    {
        return function () use ($name): void {
            $this->racedAt[] = $this->reads;
            $this->register($name);
        };
    }

    /** @param Closure(Context): mixed $act */
    private function journeyOf(Closure $act, bool $uniqueNames = true): Journey
    {
        return new class($act, $uniqueNames) extends Journey
        {
            /** @param Closure(Context): mixed $act */
            public function __construct(private readonly Closure $act, private readonly bool $uniqueNames) {}

            public function steps(): array
            {
                return [Step::make('register')->repeatable(max: 2)->act($this->act)];
            }

            public function invariants(): array
            {
                return $this->uniqueNames ? [Invariants::uniqueBy(User::class, ['name'])] : [];
            }
        };
    }
}
