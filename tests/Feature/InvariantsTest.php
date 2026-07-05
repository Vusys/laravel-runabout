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
