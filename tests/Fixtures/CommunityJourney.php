<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;

/**
 * One community's life, parameterised by name so two instances can interleave
 * as different tenants. On its own this journey can never expose the planted
 * global-count bug — with a single community the scoped and unscoped counts
 * are identical — which is exactly what interleave mode exists to fix.
 */
final class CommunityJourney extends Journey
{
    public function __construct(private readonly string $name = 'general') {}

    public function steps(): array
    {
        $service = new PostService;

        return [
            Step::make('found community')
                ->act(fn (Context $ctx): mixed => $ctx->remember('community', Community::query()->create(['name' => $this->name])))
                ->assert(fn (Context $ctx) => Assert::assertTrue($ctx->instance('community', Community::class)->exists)),

            Step::make('draft post')
                ->after('found community')
                ->repeatable(max: 3)
                ->act(fn (Context $ctx): mixed => $ctx->remember('post', $service->draft(
                    $ctx->instance('community', Community::class),
                    $ctx->pick(['Hello', 'Show and tell', 'Rules', 'Weekly thread']),
                )))
                ->assert(fn (Context $ctx) => Assert::assertSame('draft', $ctx->instance('post', Post::class)->refresh()->status)),

            Step::make('publish post')
                ->after('draft post')
                ->act(function (Context $ctx) use ($service): void {
                    $post = $ctx->instance('post', Post::class)->refresh();
                    $service->publish($post);
                    $ctx->remember('published post', $post);
                })
                ->assert(fn (Context $ctx) => Assert::assertSame('published', $ctx->instance('published post', Post::class)->refresh()->status)),

            Step::make('cast vote')
                ->after('publish post')
                ->repeatable()
                ->act(fn (Context $ctx) => $service->vote(
                    $ctx->instance('published post', Post::class)->refresh(),
                    $ctx->pick(['ana', 'ben', 'cai']),
                    $ctx->pick([1, -1]),
                ))
                ->assert(fn (Context $ctx) => Assert::assertGreaterThan(0, $ctx->instance('published post', Post::class)->votes()->count())),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariants::cachedColumnMatches(Community::class, 'posts_count', fn (Community $community): int => $community->posts()->count()),
            Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),
        ];
    }
}
