<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

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
                ->act(fn (Context $ctx) => $ctx->remember('community', Community::query()->create(['name' => 'general'])))
                ->assert(fn (Context $ctx) => Assert::assertTrue($ctx->instance('community', Community::class)->exists)),

            Step::make('draft post')
                ->after('create community')
                ->act(fn (Context $ctx) => $ctx->remember('post', $ctx->instance('community', Community::class)->posts()->create(['title' => 'Hello world'])))
                ->assert(fn (Context $ctx) => Assert::assertSame('draft', $ctx->instance('post', Post::class)->refresh()->status)),

            Step::make('publish post')
                ->after('draft post')
                ->act(fn (Context $ctx) => $service->publish($ctx->instance('post', Post::class)))
                ->assert(fn (Context $ctx) => Assert::assertSame('published', $ctx->instance('post', Post::class)->refresh()->status)),

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
        ];
    }
}
