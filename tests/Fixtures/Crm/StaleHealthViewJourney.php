<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Crm;

use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Opportunity;

/**
 * S6 — the stale-health-cache benchmark (axis: non-monotonicity). health_score
 * is recomputed and marked fresh by "view account"; the planted bug is that
 * "open opportunity" forgets to invalidate it. So the failure needs view ->
 * open: the view marks the cache fresh, then the open moves the true count on
 * without clearing the flag.
 *
 * The adversarial part: "view account" only *reads*, so a shrinker that assumed
 * reads are removable would drop it and wrongly declare the bug gone. Delta
 * debugging against an exact same-failure oracle keeps it, because removing it
 * stops the reproduction. The minimal is exactly view -> open.
 */
final class StaleHealthViewJourney extends Journey
{
    /** @var list<string> */
    private const array NAMES = ['Acme', 'Globex', 'Initech', 'Umbrella', 'Stark'];

    public function __construct(private readonly Account $account) {}

    public function steps(): array
    {
        $service = new CrmService;

        return [
            // Declared before "view account" so the canonical order is
            // open-then-view (fresh and accurate); only a shuffle that views
            // before opening leaves a fresh-but-stale cache.
            Step::make('open opportunity')
                ->repeatable(max: 8)
                ->act(fn (Context $ctx): Opportunity => $service->openOpportunity($this->account, 'Deal '.$ctx->randomInt(1, 9999), $ctx->randomInt(50, 500))),

            Step::make('view account')
                ->repeatable(max: 8)
                ->weight(2)
                ->act(fn () => $service->viewAccount($this->account)),

            Step::make('rename account')
                ->repeatable(max: 8)
                ->weight(2)
                ->act(fn (Context $ctx) => $this->account->update(['name' => $ctx->pick(self::NAMES)])),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('a fresh health_score matches the open count', function (): void {
                foreach (Account::query()->get() as $account) {
                    if (! $account->health_fresh) {
                        continue;
                    }

                    Assert::assertSame(
                        $account->opportunities()->where('stage', 'prospecting')->count(),
                        $account->health_score,
                        'A health_score marked fresh drifted from the true open-opportunity count.',
                    );
                }
            }),
        ];
    }
}
