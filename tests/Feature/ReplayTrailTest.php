<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\PostLifecycleJourney;
use Vusys\Runabout\Tests\Fixtures\PostService;
use Vusys\Runabout\Tests\TestCase;
use Vusys\Runabout\Trail;

/**
 * Explicit-order replay: a trail artifact (seed + token list) reproduces a
 * failing trail exactly, order and data both, without re-deriving it from a
 * shuffle. This is the substrate the shrinker runs candidates through.
 */
final class ReplayTrailTest extends TestCase
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

    public function test_a_failing_trail_replays_from_its_artifact(): void
    {
        PostService::$buggyRevote = true;

        [$artifact, $steps] = $this->captureFailingTrail();

        try {
            $this->journey(PostLifecycleJourney::class)->trail($artifact)->run();
            $this->fail('Expected the replayed artifact to reproduce the failure.');
        } catch (JourneyFailedException $replayed) {
            $this->assertSame('replayed', $replayed->trail()->mode());
            $this->assertSame($steps, $replayed->trail()->steps(), 'The replayed trail must take the same path as the artifact.');
            $this->assertStringContainsString('Post.score matches its source data', $replayed->getMessage());
            $this->assertStringContainsString("RUNABOUT_TRAIL='", $replayed->getMessage());
        }
    }

    public function test_a_failing_trail_replays_from_the_env_var(): void
    {
        PostService::$buggyRevote = true;

        [$artifact, $steps] = $this->captureFailingTrail();

        putenv('RUNABOUT_TRAIL='.json_encode($artifact));

        try {
            $this->journey(PostLifecycleJourney::class)->run();
            $this->fail('Expected RUNABOUT_TRAIL to reproduce the failure.');
        } catch (JourneyFailedException $replayed) {
            $this->assertSame($steps, $replayed->trail()->steps());
        } finally {
            putenv('RUNABOUT_TRAIL');
        }
    }

    public function test_a_passing_trail_round_trips_through_its_artifact(): void
    {
        $captured = null;

        $this->journey(PostLifecycleJourney::class)
            ->seed(123)
            ->onTrail(function (Trail $trail) use (&$captured): void {
                $captured = $trail;
            })
            ->run();

        $this->assertInstanceOf(Trail::class, $captured);
        $original = $captured->steps();

        $replayed = null;

        $this->journey(PostLifecycleJourney::class)
            ->trail($captured->artifact())
            ->onTrail(function (Trail $trail) use (&$replayed): void {
                $replayed = $trail;
            })
            ->run();

        $this->assertInstanceOf(Trail::class, $replayed);
        $this->assertSame($original, $replayed->steps(), 'Replaying a passing trail must reproduce its exact order.');
    }

    public function test_a_non_viable_artifact_is_rejected_as_a_broken_trail(): void
    {
        // "publish post" depends (transitively) on "create community" and
        // "draft post"; naming it alone is an order no trail could produce.
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('not viable');

        $this->journey(PostLifecycleJourney::class)
            ->trail(['seed' => 1, 'steps' => [[null, 'publish post', 1]]])
            ->run();
    }

    /**
     * Run shuffles until the revote bug bites, and return the failing trail's
     * artifact plus its labelled step list.
     *
     * @return array{0: array{seed: int, steps: list<array{0: string|null, 1: string, 2: int}>}, 1: list<string>}
     */
    private function captureFailingTrail(): array
    {
        try {
            $this->journey(PostLifecycleJourney::class)->shuffles(60)->run();
            $this->fail('Expected a shuffled trail to catch the revote bug.');
        } catch (JourneyFailedException $failed) {
            return [$failed->trail()->artifact(), $failed->trail()->steps()];
        }
    }
}
