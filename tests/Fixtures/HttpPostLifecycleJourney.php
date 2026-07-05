<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\Post;
use Vusys\Runabout\Tests\Fixtures\Models\User;

/**
 * The PostLifecycleJourney driven entirely over HTTP: same forum app, same
 * planted revote bug, but every act is an authenticated request from one of
 * three actors instead of a service call.
 */
final class HttpPostLifecycleJourney extends Journey
{
    private const array VOTERS = ['ana', 'ben', 'cai'];

    public function steps(): array
    {
        return [
            Step::make('sign up the voters')
                ->act(function (Context $ctx): void {
                    foreach (self::VOTERS as $name) {
                        $ctx->actingAs(User::query()->create(['name' => $name]), $name);
                    }
                })
                ->assert(fn (Context $ctx) => Assert::assertSame(3, User::query()->count())),

            Step::make('create community')
                ->after('sign up the voters')
                ->act(function (Context $ctx): void {
                    $response = $ctx->as($ctx->pick(self::VOTERS))->postJson('/communities', ['name' => 'general']);
                    $ctx->remember('community id', $response->json('id'));
                })
                ->assert(fn (Context $ctx) => $ctx->lastResponse()->assertCreated()),

            Step::make('draft post')
                ->after('create community')
                ->act(function (Context $ctx): void {
                    $response = $ctx->as($ctx->pick(self::VOTERS))
                        ->postJson(sprintf('/communities/%d/posts', $ctx->integer('community id')), ['title' => 'Hello world']);
                    $ctx->remember('post id', $response->json('id'));
                })
                ->assert(function (Context $ctx): void {
                    $ctx->lastResponse()->assertCreated();
                    Assert::assertSame('draft', $this->post($ctx)->status);
                }),

            Step::make('publish post')
                ->after('draft post')
                ->act(fn (Context $ctx): TestResponse => $ctx->as($ctx->pick(self::VOTERS))
                    ->postJson(sprintf('/posts/%d/publish', $ctx->integer('post id'))))
                ->assert(function (Context $ctx): void {
                    $ctx->lastResponse()->assertOk();
                    Assert::assertSame('published', $this->post($ctx)->status);
                }),

            Step::make('cast vote')
                ->after('publish post')
                ->repeatable()
                ->act(fn (Context $ctx): TestResponse => $ctx->as($ctx->pick(self::VOTERS))
                    ->postJson(sprintf('/posts/%d/vote', $ctx->integer('post id')), ['value' => $ctx->pick([1, -1])]))
                ->assert(function (Context $ctx): void {
                    $post = $this->post($ctx);

                    if ($post->status === 'locked') {
                        $ctx->lastResponse()->assertStatus(409);
                    } else {
                        $ctx->lastResponse()->assertCreated();
                        Assert::assertGreaterThan(0, $post->votes()->count());
                    }
                }),

            Step::make('lock post')
                ->after('publish post')
                ->act(fn (Context $ctx): TestResponse => $ctx->as($ctx->pick(self::VOTERS))
                    ->postJson(sprintf('/posts/%d/lock', $ctx->integer('post id'))))
                ->assert(function (Context $ctx): void {
                    $ctx->lastResponse()->assertOk();
                    Assert::assertSame('locked', $this->post($ctx)->status);
                }),
        ];
    }

    #[\Override]
    public function invariants(): array
    {
        return [
            Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),
        ];
    }

    private function post(Context $ctx): Post
    {
        return Post::query()->findOrFail($ctx->integer('post id'));
    }
}
