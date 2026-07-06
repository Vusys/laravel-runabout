<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Crm;

use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\CrmService;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Opportunity;

/**
 * S7 — the two-bug slippage benchmark (axis: same-failure identity). Both the
 * roll-up-on-reopen bug (S1) and the stale-health-view bug (S6) are live at
 * once, so a long trail can trip either invariant. Shrinking a trail that
 * failed on one must never quietly reduce to a trail that trips the *other* —
 * the shrunk failure must keep the original's signature. This journey is the
 * end-to-end guard on that promise.
 */
final class SlippageJourney extends Journey
{
    /** @var list<string> */
    private const array NAMES = ['Acme', 'Globex', 'Initech', 'Umbrella', 'Stark'];

    public function __construct(private readonly Account $account) {}

    public function steps(): array
    {
        $service = new CrmService;

        return [
            Step::make('reopen opportunity')
                ->repeatable(max: 8)
                ->act(function (Context $ctx) use ($service): void {
                    $opportunity = $this->pick($ctx, 'closed_won');

                    if ($opportunity instanceof Opportunity) {
                        $service->reopenOpportunity($opportunity);
                    }
                }),

            Step::make('win opportunity')
                ->repeatable(max: 8)
                ->act(function (Context $ctx) use ($service): void {
                    $opportunity = $this->pick($ctx, 'prospecting');

                    if ($opportunity instanceof Opportunity) {
                        $service->winOpportunity($opportunity);
                    }
                }),

            // Declared before "view account" so the canonical order stays fresh
            // and accurate on the health cache, as in S6.
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
            Invariants::cachedColumnMatches(
                Account::class,
                'won_amount',
                fn (Account $account): int => (int) $account->opportunities()->where('stage', 'closed_won')->sum('amount'),
            ),

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

    private function pick(Context $ctx, string $stage): ?Opportunity
    {
        $ids = [];

        foreach ($this->account->opportunities()->where('stage', $stage)->orderBy('id')->get() as $opportunity) {
            $ids[] = $opportunity->id;
        }

        if ($ids === []) {
            return null;
        }

        return Opportunity::query()->find($ctx->pick($ids));
    }
}
