<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\EnumPost;
use Vusys\Runabout\Tests\Fixtures\Models\Post;
use Vusys\Runabout\Tests\TestCase;

/** The invariant library's own guard rails, beyond the planted-bug journeys. */
final class InvariantsTest extends TestCase
{
    public function test_legal_transitions_accepts_a_row_born_in_a_legal_initial_state(): void
    {
        $this->draftPost();

        Invariants::legalTransitions(Post::class, 'status', ['draft' => ['published']], initial: ['draft'])
            ->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_legal_transitions_rejects_an_illegal_initial_state(): void
    {
        $this->draftPost()->update(['status' => 'published']);

        $invariant = Invariants::legalTransitions(Post::class, 'status', ['draft' => ['published']], initial: ['draft']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('appeared in state "published", which is not a legal initial state (draft)');

        $invariant->check($this->context());
    }

    public function test_legal_transitions_rejects_a_non_string_state_column(): void
    {
        $this->draftPost();

        $invariant = Invariants::legalTransitions(Post::class, 'score', []);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a state column must hold strings');

        $invariant->check($this->context());
    }

    public function test_legal_transitions_coerces_a_backed_enum_state(): void
    {
        $post = $this->draftPost();

        $invariant = Invariants::legalTransitions(EnumPost::class, 'status', ['draft' => ['published']], initial: ['draft']);

        $invariant->check($this->context());               // born 'draft' (enum coerced to its value)
        $post->update(['status' => 'published']);
        $invariant->check($this->context());               // draft -> published is legal

        $this->addToAssertionCount(1);
    }

    public function test_legal_transitions_catches_an_illegal_backed_enum_transition(): void
    {
        $post = $this->draftPost();
        $post->update(['status' => 'published']);

        $invariant = Invariants::legalTransitions(EnumPost::class, 'status', ['draft' => ['published']]);

        $invariant->check($this->context());               // first seen: published
        $post->update(['status' => 'draft']);              // published -> draft has no edge

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('made an illegal status transition: "published" -> "draft"');

        $invariant->check($this->context());
    }

    public function test_legal_transitions_honours_a_state_of_closure(): void
    {
        $post = $this->draftPost();

        // The map is upper-cased; it can only match if the closure's result is
        // what's tracked, not the raw column.
        $invariant = Invariants::legalTransitions(
            Post::class,
            'status',
            ['DRAFT' => ['PUBLISHED']],
            initial: ['DRAFT'],
            stateOf: fn (Post $p): string => strtoupper($p->status),
        );

        $invariant->check($this->context());
        $post->update(['status' => 'published']);
        $invariant->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_quota_balances_still_accepts_a_constant_allowance(): void
    {
        $this->draftPost(); // reports_remaining defaults to 2, no reports spent

        Invariants::quotaBalances(Post::class, 'reports_remaining', 2, fn (Post $post): int => $post->reports()->count())
            ->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_quota_balances_accepts_a_per_row_starting_allowance(): void
    {
        $community = Community::query()->create(['name' => 'general']);

        // Two posts with different allowances (5 and 10) and different spend.
        $small = $community->posts()->create(['title' => 'small', 'reports_remaining' => 3]);
        $large = $community->posts()->create(['title' => 'large', 'reports_remaining' => 9]);
        $small->reports()->createMany([['reporter' => 'a'], ['reporter' => 'b']]); // spent 2 -> 5 - 2 = 3
        $large->reports()->create(['reporter' => 'c']);                            // spent 1 -> 10 - 1 = 9

        Invariants::quotaBalances(
            Post::class,
            'reports_remaining',
            fn (Post $post): int => $post->title === 'small' ? 5 : 10,
            fn (Post $post): int => $post->reports()->count(),
        )->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_quota_balances_reports_the_per_row_allowance_on_drift(): void
    {
        $post = $this->draftPost();
        $post->update(['reports_remaining' => 3]);
        $post->reports()->create(['reporter' => 'a']); // allowance 5 - spent 1 = 4, but column holds 3

        $invariant = Invariants::quotaBalances(
            Post::class,
            'reports_remaining',
            fn (Post $post): int => 5,
            fn (Post $post): int => $post->reports()->count(),
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('a starting quota of 5 minus actual spend leaves 4');

        $invariant->check($this->context());
    }

    public function test_unique_by_passes_when_the_column_tuple_is_unique(): void
    {
        $community = Community::query()->create(['name' => 'general']);
        $community->posts()->create(['title' => 'first']);
        $community->posts()->create(['title' => 'second']);

        Invariants::uniqueBy(Post::class, ['community_id', 'title'])->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_unique_by_catches_a_duplicate_tuple(): void
    {
        $community = Community::query()->create(['name' => 'general']);
        $community->posts()->create(['title' => 'dupe']);
        $community->posts()->create(['title' => 'dupe']);

        $invariant = Invariants::uniqueBy(Post::class, ['community_id', 'title']);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('share (community_id, title) = ('); // names the duplicated tuple

        $invariant->check($this->context());
    }

    private function draftPost(): Post
    {
        return Community::query()->create(['name' => 'general'])
            ->posts()->create(['title' => 'hello']);
    }

    private function context(): Context
    {
        return new Context(new Randomizer(new Mt19937(1)));
    }
}
