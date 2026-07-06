<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Vusys\Runabout\Tests\Fixtures\Models\Crm\Account;
use Vusys\Runabout\Tests\Fixtures\Models\Crm\Opportunity;

/**
 * A fictional CRM's write model, with planted order-dependent bugs behind
 * static toggles (the PostService pattern). These back the shrinker benchmark
 * corpus: each bug needs a specific, often long, sequence to surface, and each
 * has a known-minimal counterexample the shrinker is graded against. See
 * docs/shrinker-benchmarks.md.
 *
 * Reads and writes go through the query builder against the current row, never
 * through a cached model instance — journeys reuse one Account across many
 * rolled-back trails, and a stale in-memory attribute would make Eloquent skip
 * an update it believes is a no-op, manufacturing failures the app never has.
 */
final class CrmService
{
    /**
     * S1 — reopening a closed-won opportunity forgets to subtract its amount
     * back out of the account's won_amount roll-up. Needs open -> win -> reopen.
     */
    public static bool $buggyReopenRollup = false;

    /**
     * S2 — closing the largest open opportunity forgets to recompute
     * largest_open_deal when other open opportunities remain, so the cache is
     * left pointing at a deal that is no longer open. Whether it fires depends
     * on which opportunity drew the larger amount — the data, not just the
     * order — which is why it is the acceptance test for position-independent
     * (seed-schema-v2) draws.
     */
    public static bool $buggyMaxDealCache = false;

    /**
     * S6 — opening an opportunity forgets to invalidate the account's health
     * cache, so a health_score computed by an earlier "view" is left marked
     * fresh while the true open count has moved on. The view is what marks the
     * cache fresh, so it is a required step even though it only reads — the
     * adversarial case for a shrinker that assumes reads are removable.
     */
    public static bool $buggyStaleHealthView = false;

    public static function reset(): void
    {
        self::$buggyReopenRollup = false;
        self::$buggyMaxDealCache = false;
        self::$buggyStaleHealthView = false;
    }

    public function openOpportunity(Account $account, string $name, int $amount): Opportunity
    {
        $opportunity = Opportunity::query()->create([
            'account_id' => $account->id,
            'name' => $name,
            'stage' => 'prospecting',
            'amount' => $amount,
        ]);

        $this->recomputeLargestOpenDeal($account->id);

        // A mutation to the open set should invalidate the health cache.
        if (! self::$buggyStaleHealthView) {
            Account::query()->whereKey($account->id)->update(['health_fresh' => false]);
        }

        return $opportunity;
    }

    public function winOpportunity(Opportunity $opportunity): void
    {
        Opportunity::query()->whereKey($opportunity->id)->update(['stage' => 'closed_won']);

        $this->addToWonAmount($opportunity->account_id, $opportunity->amount);
        $this->recomputeLargestOpenDeal($opportunity->account_id);
    }

    public function reopenOpportunity(Opportunity $opportunity): void
    {
        if (! self::$buggyReopenRollup) {
            $this->addToWonAmount($opportunity->account_id, -$opportunity->amount);
        }

        Opportunity::query()->whereKey($opportunity->id)->update(['stage' => 'prospecting']);

        $this->recomputeLargestOpenDeal($opportunity->account_id);
    }

    /** Close the largest currently-open opportunity as lost. */
    public function closeLargestOpen(Account $account): void
    {
        $largest = Opportunity::query()
            ->where('account_id', $account->id)
            ->where('stage', 'prospecting')
            ->orderByDesc('amount')
            ->orderBy('id')
            ->first();

        if (! $largest instanceof Opportunity) {
            return;
        }

        Opportunity::query()->whereKey($largest->id)->update(['stage' => 'closed_lost']);

        $remaining = Opportunity::query()->where('account_id', $account->id)->where('stage', 'prospecting')->exists();

        // The bug only bites when open opportunities remain: emptying the set
        // still (correctly) resets the cache to zero.
        if (self::$buggyMaxDealCache && $remaining) {
            return;
        }

        $this->recomputeLargestOpenDeal($account->id);
    }

    /** Read the account, recomputing and re-freshening its health cache. */
    public function viewAccount(Account $account): void
    {
        Account::query()->whereKey($account->id)->update([
            'health_score' => $this->openCount($account->id),
            'health_fresh' => true,
        ]);
    }

    private function addToWonAmount(int $accountId, int $delta): void
    {
        $account = Account::query()->findOrFail($accountId);

        Account::query()->whereKey($accountId)->update(['won_amount' => $account->won_amount + $delta]);
    }

    private function recomputeLargestOpenDeal(int $accountId): void
    {
        $largest = 0;

        foreach (Opportunity::query()->where('account_id', $accountId)->where('stage', 'prospecting')->get() as $opportunity) {
            $largest = max($largest, $opportunity->amount);
        }

        Account::query()->whereKey($accountId)->update(['largest_open_deal' => $largest]);
    }

    private function openCount(int $accountId): int
    {
        return Opportunity::query()->where('account_id', $accountId)->where('stage', 'prospecting')->count();
    }
}
