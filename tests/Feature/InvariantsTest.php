<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
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
