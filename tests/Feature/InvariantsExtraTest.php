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

/** @internal A backed enum whose value is an int, not a string — legalTransitions() must coerce ->value to a string, not just read it raw. */
enum InvariantsExtraIntState: int
{
    case Start = 1;
    case Next = 2;
}

/**
 * Two Invariants edge cases the main InvariantsTest suite doesn't reach:
 * coercing an int-backed enum's ->value to a string, and reporting a row's
 * actual scalar primary key value (not its PHP type) in failure messages.
 */
final class InvariantsExtraTest extends TestCase
{
    public function test_legal_transitions_coerces_an_int_backed_enum_value_to_a_string(): void
    {
        $this->draftPost();

        // The initial-state list only contains the string '1'. If the raw
        // int-backed enum value (1, an int) were compared instead of its
        // string-cast form ('1'), the strict in_array check below would
        // reject it as an illegal initial state.
        $invariant = Invariants::legalTransitions(
            Post::class,
            'status',
            transitions: [],
            initial: ['1'],
            stateOf: fn (Post $post): InvariantsExtraIntState => InvariantsExtraIntState::Start,
        );

        $invariant->check($this->context());

        $this->addToAssertionCount(1);
    }

    public function test_legal_transitions_rejects_an_int_backed_enum_value_outside_the_initial_list(): void
    {
        $this->draftPost();

        // Mirror image of the test above: the raw int 2 must be coerced to
        // '2' and rejected, rather than compared against ['2'] as an int
        // (which would also "pass" and mask a broken coercion in the other
        // direction).
        $invariant = Invariants::legalTransitions(
            Post::class,
            'status',
            transitions: [],
            initial: ['1'],
            stateOf: fn (Post $post): InvariantsExtraIntState => InvariantsExtraIntState::Next,
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('appeared in state "2", which is not a legal initial state (1)');

        $invariant->check($this->context());
    }

    public function test_quota_balance_failures_report_the_rows_actual_scalar_key_value(): void
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

        try {
            $invariant->check($this->context());
            $this->fail('Expected the quota drift to be reported.');
        } catch (RuntimeException $e) {
            // A scalar (int) primary key must be reported as its own value
            // ("Post 1's ..."), not as its PHP type ("Post int's ...").
            $this->assertStringContainsString(sprintf('Post %d\'s quota drifted', $post->id), $e->getMessage());
            $this->assertStringNotContainsString('Post int\'s quota drifted', $e->getMessage());
        }
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
