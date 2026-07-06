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
 * S1 — the roll-up-on-reopen benchmark (axes: length, repeat-count). An
 * account's won_amount should equal the sum of its closed-won opportunities;
 * the planted bug forgets to subtract on reopen.
 *
 * The steps are declared in a deliberately non-triggering order (reopen, win,
 * open) and win/reopen no-op when there is nothing to act on, so the canonical
 * order never provokes the bug — only a shuffle that lands open -> win ->
 * reopen does. That is what a shrinker exists for: reduce a long failing
 * shuffle to the three executions that matter, in that order.
 */
final class RollupReopenJourney extends Journey
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

            Step::make('open opportunity')
                ->repeatable(max: 8)
                ->act(function (Context $ctx) use ($service): void {
                    $service->openOpportunity($this->account, 'Deal '.$ctx->randomInt(1, 9999), $ctx->randomInt(50, 500));
                }),

            // Pure noise: it pads the trail (and consumes randomness) but touches
            // nothing the roll-up depends on, so a shrinker should strip it.
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
                'won_amount',
                fn (Account $account): int => (int) $account->opportunities()->where('stage', 'closed_won')->sum('amount'),
            ),
        ];
    }

    /** Pick a random opportunity of the account in the given stage, or null when none. */
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
