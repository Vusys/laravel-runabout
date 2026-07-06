<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature\Crm;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\Crm\RollupReopenJourney;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\TestCase;

/**
 * S1 acceptance: a long failing shuffle shrinks to the three executions that
 * matter — open, win, reopen — in that order.
 */
final class RollupReopenJourneyTest extends TestCase
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
        CrmService::$buggyReopenRollup = true;

        // Declared order is reopen, win, open — the trigger (open -> win ->
        // reopen) is a shuffle away, so the canonical trail stays green.
        $this->journey(new RollupReopenJourney($this->account()))->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_a_long_failing_shuffle_shrinks_to_the_minimal_three(): void
    {
        CrmService::$buggyReopenRollup = true;

        try {
            $this->journey(new RollupReopenJourney($this->account()))
                ->repeatHeavy()
                ->shuffles(60)
                ->run();

            $this->fail('Expected a shuffled trail to catch the roll-up bug.');
        } catch (JourneyFailedException $failure) {
            $this->assertStringContainsString('Shrunk from', $failure->getMessage());
            $this->assertStringContainsString('Account.won_amount matches its source data', $failure->getMessage());

            $this->assertSame(
                ['open opportunity', 'win opportunity', 'reopen opportunity'],
                $failure->trail()->steps(),
                'The shrunk trail should be exactly open -> win -> reopen.',
            );
        }
    }

    private function account(): Account
    {
        return Account::query()->create(['name' => 'Acme']);
    }
}
