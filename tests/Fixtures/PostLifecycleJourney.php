<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Assert;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;

final class PostLifecycleJourney extends Journey
{
    private const array VOTERS = ['ana', 'ben', 'cai'];

    public function steps(): array
    {
        $service = new PostService;

        return [
            Step::make('create community')
                ->act(fn (Context $ctx): mixed => $ctx->remember('community', Community::query()->create(['name' => 'general'])))
                ->assert(fn (Context $ctx) => Assert::assertTrue($ctx->instance('community', Community::class)->exists)),

            Step::make('draft post')
                ->after('create community')
                ->act(fn (Context $ctx): mixed => $ctx->remember('post', $ctx->instance('community', Community::class)->posts()->create(['title' => 'Hello world'])))
                ->assert(fn (Context $ctx) => Assert::assertSame('draft', $ctx->instance('post', Post::class)->refresh()->status)),

            Step::make('publish post')
                ->after('draft post')
                ->act(fn (Context $ctx) => $service->publish($ctx->instance('post', Post::class)))
                ->assert(fn (Context $ctx) => Assert::assertSame('published', $ctx->instance('post', Post::class)->refresh()->status)),

            Step::make('a new day dawns')
                ->after('publish post')
                ->act(fn (Context $ctx) => $ctx->travel('+1 day')),

            Step::make('cast vote')
                ->after('publish post')
                ->repeatable()
                ->act(function (Context $ctx) use ($service): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    try {
                        $service->vote($post, $ctx->pick(self::VOTERS), $ctx->pick([1, -1]));
                        $ctx->remember('last vote rejected', false);
                    } catch (RuntimeException) {
                        $ctx->remember('last vote rejected', true);
                    }
                })
                ->assert(function (Context $ctx): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    if ($post->trashed()) {
                        // Whether removed posts accept votes is the invariant
                        // library's to police; the step claims nothing here.
                        return;
                    }

                    if (in_array($post->status, ['locked', 'archived'], true)) {
                        Assert::assertTrue($ctx->get('last vote rejected'), 'Voting on a locked or archived post must be rejected.');
                    } else {
                        Assert::assertFalse($ctx->get('last vote rejected'), 'Voting on a published post must succeed.');
                        Assert::assertGreaterThan(0, $post->votes()->count());
                    }
                }),

            Step::make('report post')
                ->after('draft post')
                ->repeatable()
                ->act(function (Context $ctx) use ($service): void {
                    $post = $ctx->instance('post', Post::class)->refresh();
                    $reporter = $ctx->pick(self::VOTERS);

                    $alreadyReported = $post->reports()->where('reporter', $reporter)->exists();
                    $ctx->remember(
                        'report should be rejected',
                        $post->trashed() || $post->status === 'draft' || (! $alreadyReported && $post->reports_remaining <= 0),
                    );

                    try {
                        $service->report($post, $reporter);
                        $ctx->remember('report rejected', false);
                    } catch (RuntimeException) {
                        $ctx->remember('report rejected', true);
                    }
                })
                ->assert(fn (Context $ctx) => Assert::assertSame(
                    $ctx->get('report should be rejected'),
                    $ctx->get('report rejected'),
                    'The report guard disagreed with the preconditions the step observed.',
                )),

            Step::make('lock post')
                ->after('publish post')
                ->act(fn (Context $ctx) => $service->lock($ctx->instance('post', Post::class)))
                ->assert(fn (Context $ctx) => Assert::assertSame('locked', $ctx->instance('post', Post::class)->refresh()->status)),

            Step::make('archive post')
                ->after('draft post')
                ->act(function (Context $ctx) use ($service): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    try {
                        $service->archive($post);
                        $ctx->remember('archive rejected', false);
                    } catch (RuntimeException) {
                        $ctx->remember('archive rejected', true);
                    }
                })
                ->assert(function (Context $ctx): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    // Deliberately weak: it accepts whatever the service did.
                    // Guarding WHICH transitions are legal is the job of the
                    // legalTransitions invariant.
                    $ctx->get('archive rejected') === true
                        ? Assert::assertNotSame('archived', $post->status)
                        : Assert::assertSame('archived', $post->status);
                }),

            Step::make('remove post')
                ->after('draft post')
                ->act(function (Context $ctx) use ($service): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    if (! $post->trashed()) {
                        $service->remove($post);
                    }
                })
                ->assert(fn (Context $ctx) => Assert::assertTrue($ctx->instance('post', Post::class)->refresh()->trashed())),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),

            Invariants::quotaBalances(Post::class, 'reports_remaining', 2, fn (Post $post): int => $post->reports()->count()),

            Invariants::legalTransitions(Post::class, 'status', [
                'draft' => ['published'],
                'published' => ['locked'],
                'locked' => ['archived'],
            ], initial: ['draft']),

            Invariants::trashedLeavesNoLiveChildren(
                Post::class,
                fn (Post $post): int => $post->votes()->count() + $post->reports()->count(),
                'votes or reports',
            ),

            // A bespoke invariant alongside the built-ins: only fresh buckets
            // make a claim, because the bucket legally resets lazily on write.
            Invariant::make('daily vote bucket matches votes cast today', function (): void {
                $today = Date::today()->toDateString();

                foreach (Post::query()->get() as $post) {
                    if ($post->votes_today_date?->toDateString() !== $today) {
                        continue;
                    }

                    Assert::assertSame(
                        $post->votes()->whereDate('cast_on', $today)->count(),
                        $post->votes_today,
                        sprintf("Post %d's daily vote bucket drifted from the votes actually cast today.", $post->id),
                    );
                }
            }),
        ];
    }
}
