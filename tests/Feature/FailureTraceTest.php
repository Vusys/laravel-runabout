<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use PHPUnit\Util\ExcludeList;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\TestCase;

final class FailureTraceTest extends TestCase
{
    public function test_running_a_journey_excludes_the_package_source_from_phpunit_traces(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('noop')];
            }
        };

        (new PendingJourney($journey, fn (Closure $trail) => $trail()))->shuffles(0)->run();

        $this->assertContains(dirname(__DIR__, 2).'/src', (new ExcludeList(true))->getExcludedDirectories());
    }
}
