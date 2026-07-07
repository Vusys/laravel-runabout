<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Performance;

use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\CommunityJourney;
use Vusys\Runabout\Tests\Fixtures\PostLifecycleJourney;
use Vusys\Runabout\Tests\Fixtures\PostService;

/**
 * Throughput benchmarks for the journey runner — the package's hot path.
 *
 * Each benchmark runs a (passing) journey through a growing number of
 * seeded shuffles and records wall-clock + query count. Bencher tracks
 * the per-scale latency over time so an engine regression surfaces as a
 * threshold breach on a PR.
 */
final class JourneyRunnerBenchmarkTest extends PerformanceTestCase
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

    public function test_post_lifecycle_shuffle_throughput(): void
    {
        foreach ($this->scales() as $shuffles) {
            $this->bench("post-lifecycle / {$shuffles} shuffles", function () use ($shuffles): void {
                $this->journey(PostLifecycleJourney::class)->shuffles($shuffles)->run();
            });
        }

        $this->assertBenchmarksRan();
    }

    public function test_interleaved_community_shuffle_throughput(): void
    {
        foreach ($this->scales() as $shuffles) {
            $this->bench("community-interleaved / {$shuffles} shuffles", function () use ($shuffles): void {
                $this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))
                    ->shuffles($shuffles)
                    ->run();
            });
        }

        $this->assertBenchmarksRan();
    }
}
