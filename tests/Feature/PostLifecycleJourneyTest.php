<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\PostLifecycleJourney;
use Vusys\Runabout\Tests\Fixtures\PostService;
use Vusys\Runabout\Tests\TestCase;

final class PostLifecycleJourneyTest extends TestCase
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

    public function test_the_journey_passes_when_the_app_is_correct(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(50)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_canonical_order_misses_every_planted_bug(): void
    {
        PostService::$buggyRevote = true;
        PostService::$buggyStaleBucket = true;
        PostService::$buggyArchiveGuard = true;
        PostService::$buggyVoteOnRemoved = true;
        PostService::$buggyReportQuota = true;

        // The declared order votes once, reports once, crosses the day
        // boundary before voting, archives from locked, and removes last —
        // every planted bug needs an ordering the canonical trail never takes.
        $this->journey(PostLifecycleJourney::class)->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_shuffling_finds_the_planted_revote_bug(): void
    {
        PostService::$buggyRevote = true;

        $this->assertShufflingCatches('Post.score matches its source data', shuffles: 60);
    }

    public function test_shuffling_finds_the_planted_stale_bucket_bug(): void
    {
        PostService::$buggyStaleBucket = true;

        // Only a vote → new day → vote trail triggers this bug — rare under a
        // uniform picker. That rarity is what repeat-heavy mode exists to fix.
        $this->assertShufflingCatches('daily vote bucket matches votes cast today', shuffles: 60);
    }

    public function test_shuffling_finds_the_planted_archive_guard_bug(): void
    {
        PostService::$buggyArchiveGuard = true;

        $this->assertShufflingCatches('Post.status only makes legal transitions', shuffles: 10);
    }

    public function test_shuffling_finds_the_planted_vote_on_removed_bug(): void
    {
        PostService::$buggyVoteOnRemoved = true;

        $this->assertShufflingCatches('trashed Post rows keep no live votes or reports', shuffles: 10);
    }

    public function test_shuffling_finds_the_planted_report_quota_bug(): void
    {
        PostService::$buggyReportQuota = true;

        $this->assertShufflingCatches('Post.reports_remaining balances against its quota', shuffles: 30);
    }

    public function test_replaying_the_failing_seed_reproduces_the_failure(): void
    {
        PostService::$buggyRevote = true;

        try {
            $this->journey(PostLifecycleJourney::class)->shuffles(60)->run();
            $this->fail('Expected a shuffled trail to catch the revote bug.');
        } catch (JourneyFailedException $first) {
            $seed = $first->trail()->seed();

            try {
                $this->journey(PostLifecycleJourney::class)->seed($seed)->run();
                $this->fail('Expected the replayed seed to reproduce the failure.');
            } catch (JourneyFailedException $replayed) {
                $this->assertSame($first->trail()->steps(), $replayed->trail()->steps());
            }
        }
    }

    private function assertShufflingCatches(string $invariant, int $shuffles): void
    {
        try {
            $this->journey(PostLifecycleJourney::class)->shuffles($shuffles)->run();
            $this->fail(sprintf('Expected a shuffled trail to violate "%s".', $invariant));
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString($invariant, $e->getMessage());
            $this->assertStringContainsString('RUNABOUT_SEED=', $e->getMessage());
        }
    }
}
