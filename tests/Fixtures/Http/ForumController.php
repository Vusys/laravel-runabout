<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
use Vusys\Runabout\Tests\Fixtures\Models\Post;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\Fixtures\PostLockedException;
use Vusys\Runabout\Tests\Fixtures\PostService;

final class ForumController
{
    public function createCommunity(Request $request): JsonResponse
    {
        $validated = $request->validate(['name' => ['required', 'string']]);

        $community = Community::query()->create(['name' => $validated['name']]);

        return new JsonResponse(['id' => $community->id], 201);
    }

    public function draftPost(Request $request, int $community): JsonResponse
    {
        $validated = $request->validate(['title' => ['required', 'string']]);

        $post = Community::query()->findOrFail($community)
            ->posts()->create(['title' => $validated['title']]);

        return new JsonResponse(['id' => $post->id, 'status' => $post->refresh()->status], 201);
    }

    public function publish(int $post): JsonResponse
    {
        $post = Post::query()->findOrFail($post);

        try {
            (new PostService)->publish($post);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => $post->refresh()->status]);
    }

    public function lock(int $post): JsonResponse
    {
        $post = Post::query()->findOrFail($post);

        try {
            (new PostService)->lock($post);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['status' => $post->refresh()->status]);
    }

    public function vote(Request $request, int $post): JsonResponse
    {
        $user = $request->user();

        if (! $user instanceof User) {
            return new JsonResponse(['message' => 'Sign in to vote.'], 401);
        }

        $validated = $request->validate(['value' => ['required', 'integer', 'in:-1,1']]);

        $post = Post::query()->findOrFail($post);

        try {
            (new PostService)->vote($post, $user->name, (int) $validated['value']);
        } catch (PostLockedException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 409);
        } catch (RuntimeException $e) {
            return new JsonResponse(['message' => $e->getMessage()], 422);
        }

        return new JsonResponse(['score' => $post->refresh()->score], 201);
    }
}
