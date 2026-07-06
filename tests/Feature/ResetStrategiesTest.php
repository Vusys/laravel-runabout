<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * resetConnections() rolls back a transaction on several connections (the
 * multi-connection equivalent of the default reset) and resetExternal() runs a
 * cleanup after each trail for non-transactional stores; the two compose.
 */
final class ResetStrategiesTest extends TestCase
{
    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('database.default', 'sqlite');
        $app['config']->set('database.connections.sqlite', ['driver' => 'sqlite', 'database' => ':memory:']);
        $app['config']->set('database.connections.secondary', ['driver' => 'sqlite', 'database' => ':memory:']);
    }

    #[\Override]
    protected function defineDatabaseMigrations(): void
    {
        parent::defineDatabaseMigrations();

        Schema::connection('secondary')->create('widgets', function (Blueprint $table): void {
            $table->increments('id');
        });
    }

    use RunsJourneys;

    public function test_reset_connections_rolls_back_every_transacted_connection(): void
    {
        $this->journey($this->twoConnectionJourney())
            ->resetConnections('sqlite', 'secondary')
            ->shuffles(3)
            ->run();

        // Every trail rolled back, on both connections, so nothing survives.
        $this->assertSame(0, User::query()->count());
        $this->assertSame(0, DB::connection('secondary')->table('widgets')->count());
    }

    public function test_reset_external_cleanup_runs_after_every_trail(): void
    {
        $cleanups = 0;

        // resetExternal() alone transacts just the default connection, so this
        // journey writes only there; the cleanup fires once per trail.
        $this->journey($this->defaultConnectionJourney())
            ->resetExternal(function () use (&$cleanups): void {
                $cleanups++;
            })
            ->shuffles(3)
            ->run();

        // Canonical trail + 3 shuffles.
        $this->assertSame(4, $cleanups);
    }

    public function test_reset_connections_and_external_compose(): void
    {
        $order = [];

        $this->journey($this->twoConnectionJourney())
            ->resetConnections('sqlite', 'secondary')
            ->resetExternal(function () use (&$order): void {
                // Runs after the rollback: the DB is already clean here.
                $order[] = User::query()->count();
            })
            ->shuffles(2)
            ->run();

        $this->assertSame([0, 0, 0], $order);
    }

    private function defaultConnectionJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('write to the default connection')
                        ->act(fn (Context $ctx) => User::query()->create(['name' => 'x']))
                        ->assert(fn (Context $ctx) => Assert::assertSame(1, User::query()->count())),
                ];
            }
        };
    }

    private function twoConnectionJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('write to both connections')
                        ->act(function (Context $ctx): void {
                            User::query()->create(['name' => 'x']);
                            DB::connection('secondary')->table('widgets')->insert(['id' => 1]);
                        })
                        // Each trail sees exactly its own writes — nothing leaks
                        // in from a prior trail, on either connection.
                        ->assert(function (Context $ctx): void {
                            Assert::assertSame(1, User::query()->count());
                            Assert::assertSame(1, DB::connection('secondary')->table('widgets')->count());
                        }),
                ];
            }
        };
    }
}
