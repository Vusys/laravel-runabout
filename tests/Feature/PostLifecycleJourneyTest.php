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

        PostService::$buggyRevote = false;
    }

    protected function tearDown(): void
    {
        PostService::$buggyRevote = false;

        parent::tearDown();
    }

    public function test_the_journey_passes_when_the_app_is_correct(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_canonical_order_misses_the_planted_revote_bug(): void
    {
        PostService::$buggyRevote = true;

        // The declared order casts a single vote, so the score never drifts.
        $this->journey(PostLifecycleJourney::class)->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_shuffling_finds_the_planted_revote_bug(): void
    {
        PostService::$buggyRevote = true;

        try {
            $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
            $this->fail('Expected a shuffled trail to catch the revote bug.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('post score equals sum of votes', $e->getMessage());
            $this->assertStringContainsString('cast vote', $e->getMessage());
            $this->assertStringContainsString('RUNABOUT_SEED=', $e->getMessage());
        }
    }

    public function test_replaying_the_failing_seed_reproduces_the_failure(): void
    {
        PostService::$buggyRevote = true;

        try {
            $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
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
}
