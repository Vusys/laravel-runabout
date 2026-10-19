<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\Fixtures\StderrCapture;
use Vusys\Runabout\Tests\TestCase;

/** SQLite drops lock clauses, so racing() runs the request untouched and says so once per run. */
final class RacingOnSqliteTest extends TestCase
{
    use RunsJourneys;

    private int $competitors = 0;

    public function test_the_request_runs_without_interference_and_the_run_says_so_once(): void
    {
        $journey = new class(fn (): int => $this->competitors++) extends Journey
        {
            public function __construct(private readonly \Closure $competitor) {}

            public function steps(): array
            {
                return [Step::make('register')->repeatable(max: 3)->act(fn (Context $ctx): mixed => $ctx->racing(
                    table: 'users',
                    competitor: $this->competitor,
                    request: fn () => User::query()->where('name', 'ann')->exists() ?: User::query()->create(['name' => 'ann']),
                ))];
            }
        };

        $output = StderrCapture::of(fn () => $this->journey($journey)->shuffles(3)->run());
        $again = StderrCapture::of(fn () => $this->journey($journey)->shuffles(0)->run());

        $warning = "\n[runabout] racing() ran its request on \"users\" without interference: Laravel's sqlite grammar drops lock clauses, so an unlocked read can't be told from a locked one. Run race journeys on MySQL, MariaDB or Postgres.\n";
        $this->assertSame($warning, $output);
        $this->assertSame($warning, $again);
        $this->assertSame(0, $this->competitors);
    }
}
