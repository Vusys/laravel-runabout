<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Support\Facades\Date;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;

final class PostLifecycleJourney extends Journey
{
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
                        $service->vote($post, $ctx->pick(['ana', 'ben', 'cai']), $ctx->pick([1, -1]));
                        $ctx->remember('last vote rejected', false);
                    } catch (PostLockedException) {
                        $ctx->remember('last vote rejected', true);
                    }
                })
                ->assert(function (Context $ctx): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    if ($post->status === 'locked') {
                        Assert::assertTrue($ctx->get('last vote rejected'), 'Voting on a locked post must be rejected.');
                    } else {
                        Assert::assertFalse($ctx->get('last vote rejected'), 'Voting on a published post must succeed.');
                        Assert::assertGreaterThan(0, $post->votes()->count());
                    }
                }),

            Step::make('lock post')
                ->after('publish post')
                ->act(fn (Context $ctx) => $service->lock($ctx->instance('post', Post::class)))
                ->assert(fn (Context $ctx) => Assert::assertSame('locked', $ctx->instance('post', Post::class)->refresh()->status)),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariant::make('post score equals sum of votes', function (): void {
                foreach (Post::query()->get() as $post) {
                    Assert::assertSame(
                        (int) $post->votes()->sum('value'),
                        $post->score,
                        sprintf('Cached score of post %d drifted from its votes.', $post->id),
                    );
                }
            }),

            Invariant::make('daily vote bucket matches votes cast today', function (): void {
                $today = Date::today()->toDateString();

                foreach (Post::query()->get() as $post) {
                    if ($post->votes_today_date?->toDateString() !== $today) {
                        continue; // A stale bucket is legal — it resets lazily on the next write.
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
