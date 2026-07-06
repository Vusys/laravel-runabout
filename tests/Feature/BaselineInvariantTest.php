<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;
use Vusys\Runabout\Tests\TestCase;

/**
 * Invariant::fromStart() checks an invariant once before the first step, so a
 * state invariant on a row that existed before the journey observes its true
 * initial state — closing the gap where the opening step's transition would
 * otherwise be mistaken for the initial state.
 */
final class BaselineInvariantTest extends TestCase
{
    public function test_from_start_lets_a_pre_existing_rows_first_transition_be_policed_as_legal(): void
    {
        $post = $this->draftPost();

        (new JourneyRunner)->run($this->transitionJourney($post->id, 'published', fromStart: true), 1, shuffle: false);

        // draft -> published is legal; the baseline observation saw 'draft', so
        // the opening step's transition is judged against it and passes.
        $this->assertSame('published', $post->fresh()?->status);
    }

    public function test_from_start_catches_an_illegal_first_transition_of_a_pre_existing_row(): void
    {
        $post = $this->draftPost();

        $this->expectException(JourneyFailedException::class);
        $this->expectExceptionMessage('illegal status transition: "draft" -> "archived"');

        (new JourneyRunner)->run($this->transitionJourney($post->id, 'archived', fromStart: true), 1, shuffle: false);
    }

    public function test_without_from_start_a_pre_existing_rows_legal_transition_is_misread_as_an_illegal_initial(): void
    {
        $post = $this->draftPost();

        // The gap fromStart() closes: with no baseline, the invariant first sees
        // the post already 'published' and reads that as an illegal initial.
        $this->expectException(JourneyFailedException::class);
        $this->expectExceptionMessage('not a legal initial state');

        (new JourneyRunner)->run($this->transitionJourney($post->id, 'published', fromStart: false), 1, shuffle: false);
    }

    private function transitionJourney(int $postId, string $to, bool $fromStart): Journey
    {
        return new class($postId, $to, $fromStart) extends Journey
        {
            public function __construct(
                private readonly int $postId,
                private readonly string $to,
                private readonly bool $fromStart,
            ) {}

            public function steps(): array
            {
                return [
                    Step::make('transition')->act(fn (Context $ctx) => Post::query()->whereKey($this->postId)->update(['status' => $this->to])),
                ];
            }

            public function invariants(): array
            {
                $invariant = Invariants::legalTransitions(
                    Post::class,
                    'status',
                    ['draft' => ['published'], 'published' => ['locked'], 'locked' => ['archived']],
                    initial: ['draft'],
                );

                return [$this->fromStart ? $invariant->fromStart() : $invariant];
            }
        };
    }

    private function draftPost(): Post
    {
        return Community::query()->create(['name' => 'general'])
            ->posts()->create(['title' => 'hello']);
    }
}
