<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\HttpPostLifecycleJourney;
use Vusys\Runabout\Tests\Fixtures\Models;
use Vusys\Runabout\Tests\Fixtures\PostService;
use Vusys\Runabout\Tests\TestCase;

final class HttpPostLifecycleJourneyTest extends TestCase
{
    use RunsJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        PostService::$buggyRevote = false;
        PostService::$buggyStaleBucket = false;
    }

    protected function tearDown(): void
    {
        PostService::$buggyRevote = false;
        PostService::$buggyStaleBucket = false;

        parent::tearDown();
    }

    public function test_the_http_journey_passes_when_the_app_is_correct(): void
    {
        $this->journey(HttpPostLifecycleJourney::class)->shuffles(25)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_canonical_order_misses_the_planted_revote_bug(): void
    {
        PostService::$buggyRevote = true;

        // The declared order casts a single vote, so the score never drifts.
        $this->journey(HttpPostLifecycleJourney::class)->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_truncation_reset_isolates_trails_and_leaves_the_database_clean(): void
    {
        // The journey's first step asserts exactly three users exist, so any
        // state leaking between trails would fail it immediately.
        $this->journey(HttpPostLifecycleJourney::class)
            ->shuffles(10)
            ->resetByTruncating('votes', 'posts', 'communities', 'users')
            ->run();

        $this->assertSame(0, Models\User::query()->count());
        $this->assertSame(0, Models\Community::query()->count());
        $this->assertSame(0, Models\Post::query()->count());
        $this->assertSame(0, Models\Vote::query()->count());
    }

    public function test_shuffling_finds_the_planted_revote_bug_over_http(): void
    {
        PostService::$buggyRevote = true;

        try {
            $this->journey(HttpPostLifecycleJourney::class)->shuffles(25)->run();
            $this->fail('Expected a shuffled trail to catch the revote bug over HTTP.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('post score equals sum of votes', $e->getMessage());
            $this->assertStringContainsString('cast vote', $e->getMessage());
            $this->assertStringContainsString('RUNABOUT_SEED=', $e->getMessage());
        }
    }
}
