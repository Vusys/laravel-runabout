<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature\Crm;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\Crm\StaleHealthViewJourney;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\TestCase;

/**
 * S6 acceptance (the adversarial case): "view account" only reads, yet it is
 * required to reproduce the bug. The shrinker must keep it — a candidate that
 * drops it passes, so the same-failure oracle rejects that removal. The minimal
 * is view -> open.
 */
final class StaleHealthViewJourneyTest extends TestCase
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
        CrmService::$buggyStaleHealthView = true;

        $this->journey(new StaleHealthViewJourney($this->account()))->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_shrinking_keeps_the_read_that_the_bug_needs(): void
    {
        CrmService::$buggyStaleHealthView = true;

        try {
            $this->journey(new StaleHealthViewJourney($this->account()))
                ->repeatHeavy()
                ->shuffles(60)
                ->run();

            $this->fail('Expected a shuffled trail to catch the stale-health bug.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString('Shrunk from', $failure->getMessage());

            $steps = $failure->trail()->steps();

            // The read survived shrinking despite touching no write path.
            $this->assertContains('view account', $steps, 'The shrinker dropped the read the bug depends on.');
            $this->assertSame(['view account', 'open opportunity'], $steps);
        }
    }

    private function account(): Account
    {
        return Account::query()->create(['name' => 'Acme']);
    }
}
