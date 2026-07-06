<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature\Crm;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\Crm\MaxDealCacheJourney;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\TestCase;

/**
 * S2 acceptance: the bug is data-decisive (it needs the closed opportunity to
 * have drawn the larger amount), so it only reproduces because seed-schema-v2
 * keeps each surviving open's drawn amount stable as the trail is shuffled and
 * shrunk. The minimal is two opens and a close.
 */
final class MaxDealCacheJourneyTest extends TestCase
{
    use RunsJourneys;

    protected function setUp(): void
    {
        parent::setUp();

        CrmService::reset();
    }

    protected function tearDown(): void
    {
        CrmService::reset();

        parent::tearDown();
    }

    public function test_the_canonical_order_never_provokes_the_bug(): void
    {
        CrmService::$buggyMaxDealCache = true;

        $this->journey(new MaxDealCacheJourney($this->account()))->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_a_long_failing_shuffle_shrinks_to_two_opens_and_a_close(): void
    {
        CrmService::$buggyMaxDealCache = true;

        try {
            $this->journey(new MaxDealCacheJourney($this->account()))
                ->repeatHeavy()
                ->shuffles(60)
                ->run();

            $this->fail('Expected a shuffled trail to catch the largest-open-deal bug.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString('Shrunk from', $failure->getMessage());
            $this->assertStringContainsString('Account.largest_open_deal matches its source data', $failure->getMessage());

            $this->assertSame(
                ['open opportunity', 'open opportunity', 'close largest opportunity'],
                $failure->trail()->steps(),
                'The shrunk trail should be two opens (so one survives) and a close.',
            );

            // And it reproduces from its artifact — the decisive amounts survive
            // because run indices key the data stream (seed schema v2).
            $this->assertShrunkTrailReproduces($failure);
        }
    }

    private function assertShrunkTrailReproduces(JourneyFailedException $failure): void
    {
        try {
            $this->journey(new MaxDealCacheJourney($this->account()))
                ->trail($failure->trail()->artifact())
                ->run();

            $this->fail('Expected the shrunk artifact to reproduce the failure.');
        } catch (JourneyFailedException $replayed) {
            $this->assertStringContainsString('Account.largest_open_deal matches its source data', $replayed->getMessage());
        }
    }

    private function account(): Account
    {
        return Account::query()->create(['name' => 'Acme']);
    }
}
