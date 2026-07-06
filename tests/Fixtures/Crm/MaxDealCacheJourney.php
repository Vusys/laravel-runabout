<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Crm;

use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Opportunity;

/**
 * S2 — the largest-open-deal cache benchmark (axis: data-decisive draws). The
 * cache should track the biggest open opportunity's amount; the planted bug
 * fails to recompute it when the largest is closed while others remain open.
 * Whether a trail triggers the bug depends on which "open opportunity" drew the
 * larger amount — the data, not just the order — so this is the acceptance test
 * for seed-schema-v2: a surviving open keeps its drawn amount when the trail is
 * shuffled or shrunk, so the "which is larger" relationship (and the failure)
 * is preserved. The minimal is two opens and a close.
 */
final class MaxDealCacheJourney extends Journey
{
    /** @var list<string> */
    private const array NAMES = ['Acme', 'Globex', 'Initech', 'Umbrella', 'Stark'];

    public function __construct(private readonly Account $account) {}

    public function steps(): array
    {
        $service = new CrmService;

        return [
            Step::make('close largest opportunity')
                ->repeatable(max: 8)
                ->act(fn () => $service->closeLargestOpen($this->account)),

            Step::make('open opportunity')
                ->repeatable(max: 8)
                ->act(fn (Context $ctx): Opportunity => $service->openOpportunity($this->account, 'Deal '.$ctx->randomInt(1, 9999), $ctx->randomInt(50, 500))),

            Step::make('rename account')
                ->repeatable(max: 8)
                ->weight(2)
                ->act(fn (Context $ctx) => $this->account->update(['name' => $ctx->pick(self::NAMES)])),

            Step::make('log a note')
                ->repeatable(max: 8)
                ->weight(2)
                ->act(fn (Context $ctx) => $this->account->update(['name' => $this->account->name.$ctx->pick(['.', '!', '*'])])),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariants::cachedColumnMatches(
                Account::class,
                'largest_open_deal',
                fn (Account $account): int => $this->maxOpenAmount($account),
            ),
        ];
    }

    private function maxOpenAmount(Account $account): int
    {
        $max = 0;

        foreach ($account->opportunities()->where('stage', 'prospecting')->get() as $opportunity) {
            $max = max($max, $opportunity->amount);
        }

        return $max;
    }
}
