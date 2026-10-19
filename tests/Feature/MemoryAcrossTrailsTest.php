<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\HttpPostLifecycleJourney;
use Vusys\Runabout\Tests\Fixtures\PostService;
use Vusys\Runabout\Tests\TestCase;

/**
 * A long HTTP-driven run holds no more memory after a hundred trails than
 * after ten: nothing Runabout keeps grows with the trails run.
 */
final class MemoryAcrossTrailsTest extends TestCase
{
    use RunsJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        PostService::reset();
    }

    protected function tearDown(): void
    {
        PostService::reset();

        parent::tearDown();
    }

    public function test_memory_stays_flat_across_trails(): void
    {
        /** @var list<int> $samples */
        $samples = [];

        $this->journey(HttpPostLifecycleJourney::class)
            ->shuffles(110)
            ->minLength(20)
            ->onTrail(function () use (&$samples): void {
                gc_collect_cycles();
                $samples[] = memory_get_usage();
            })
            ->run();

        // The first trails warm up autoloading, routes and the app; after
        // that a trail should leave nothing behind. Measured growth is under
        // 10 KB over 100 trails; 512 KB is room for PHP and Laravel versions.
        $this->assertCount(111, $samples);
        $this->assertLessThan(512 * 1024, $samples[110] - $samples[10]);
    }
}
