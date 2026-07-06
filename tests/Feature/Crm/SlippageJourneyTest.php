<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature\Crm;

use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Tests\Fixtures\Crm\SlippageJourney;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\TestCase;

/**
 * S7 acceptance: with both the roll-up bug and the stale-health bug live, a
 * trail that fails on one must not be shrunk into a trail that trips the other.
 * The shrinker only keeps candidates that reproduce the original signature.
 */
final class SlippageJourneyTest extends TestCase
{
    use RunsJourneys;

    private const string ROLLUP = 'Account.won_amount matches its source data';

    private const string HEALTH = 'a fresh health_score matches the open count';

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

    public function test_shrinking_a_roll_up_failure_never_slips_to_the_health_bug(): void
    {
        CrmService::$buggyReopenRollup = true;
        CrmService::$buggyStaleHealthView = true;

        $account = $this->account();
        $seed = $this->firstSeedFailingOn(self::ROLLUP, $account);

        $this->assertNotNull($seed, 'Expected a seed whose unshrunk failure is the roll-up bug.');

        try {
            $this->journey(new SlippageJourney($account))->repeatHeavy()->seed($seed)->run();
            $this->fail('Expected the trail to fail.');
        } catch (JourneyFailedException $shrunk) {
            $this->assertStringContainsString('Shrunk from', $shrunk->getMessage());
            $this->assertStringContainsString(self::ROLLUP, $shrunk->getMessage());
            $this->assertStringNotContainsString(self::HEALTH, $shrunk->getMessage(), 'Shrinking slipped to a different bug.');
        }
    }

    /**
     * Find a seed whose *unshrunk* failure is the given invariant, so the test
     * starts from a known original signature.
     */
    private function firstSeedFailingOn(string $invariant, Account $account): ?int
    {
        putenv('RUNABOUT_SHRINK=0');

        try {
            for ($i = 1; $i <= 200; $i++) {
                $seed = crc32(SlippageJourney::class.'#'.$i);

                try {
                    $this->journey(new SlippageJourney($account))->repeatHeavy()->seed($seed)->run();
                } catch (JourneyFailedException $failure) {
                    if (str_contains($failure->getMessage(), $invariant)) {
                        return $seed;
                    }
                }
            }
        } finally {
            putenv('RUNABOUT_SHRINK');
        }

        return null;
    }

    private function account(): Account
    {
        return Account::query()->create(['name' => 'Acme']);
    }
}
