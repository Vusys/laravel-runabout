<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\CommunityJourney;
use Vusys\Runabout\Tests\Fixtures\PostService;
use Vusys\Runabout\Tests\TestCase;

final class CommunityJourneyTest extends TestCase
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

    public function test_interleaved_communities_pass_when_the_app_is_correct(): void
    {
        $this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))
            ->shuffles(15)
            ->run();

        $this->addToAssertionCount(1);
    }

    public function test_single_instance_shuffles_provably_cannot_detect_the_global_count_bug(): void
    {
        PostService::$buggyGlobalPostCount = true;

        // With one community the scoped and unscoped counts are identical,
        // so every single-instance trail is green no matter how it shuffles.
        $this->journey(new CommunityJourney('solo'))->shuffles(40)->run();

        $this->addToAssertionCount(1);
    }

    public function test_interleaved_communities_detect_the_tenant_bleed(): void
    {
        PostService::$buggyGlobalPostCount = true;

        try {
            $this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))
                ->shuffles(5)
                ->run();
            $this->fail('Expected an interleaved trail to catch the tenant bleed.');
        } catch (JourneyFailedException $e) {
            // Even the canonical interleaved baseline (A's journey in full,
            // then B's) exposes this bleed: B's first unscoped count already
            // includes A's posts.
            $this->assertStringContainsString('Community.posts_count matches its source data', $e->getMessage());
            $this->assertMatchesRegularExpression('/[AB]: draft post/', $e->getMessage());
        }
    }

    public function test_an_interleaved_failure_replays_identically(): void
    {
        PostService::$buggyGlobalPostCount = true;

        for ($seed = 1; $seed <= 30; $seed++) {
            try {
                $this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))->seed($seed)->run();
            } catch (JourneyFailedException $first) {
                try {
                    $this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))->seed($seed)->run();
                    $this->fail('Expected the replayed seed to reproduce the failure.');
                } catch (JourneyFailedException $replayed) {
                    $this->assertSame($first->trail()->steps(), $replayed->trail()->steps());

                    return;
                }
            }
        }

        $this->fail('No seed in 1..30 produced an interleaved failure.');
    }
}
