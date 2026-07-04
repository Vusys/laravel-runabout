<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use RuntimeException;
use Vusys\Runabout\Tests\Fixtures\Models\Post;

final class PostService
{
    /**
     * Planted bug: when replacing an existing vote, forget to remove the old
     * vote's contribution from the cached score. Only a REVOTE (the same
     * voter voting twice on one post) triggers it, so the canonical
     * one-vote-per-trail order stays green and only shuffled repeats catch it.
     */
    public static bool $buggyRevote = false;

    public function publish(Post $post): void
    {
        if ($post->status !== 'draft') {
            throw new RuntimeException('Only drafts can be published.');
        }

        $post->update(['status' => 'published']);
    }

    public function lock(Post $post): void
    {
        if ($post->status !== 'published') {
            throw new RuntimeException('Only published posts can be locked.');
        }

        $post->update(['status' => 'locked']);
    }

    public function vote(Post $post, string $voter, int $value): void
    {
        if ($post->status === 'locked') {
            throw new PostLockedException('Post is locked.');
        }

        if ($post->status === 'draft') {
            throw new RuntimeException('Cannot vote on a draft.');
        }

        $score = $post->score + $value;

        $existing = $post->votes()->where('voter', $voter)->first();

        if ($existing !== null) {
            $existing->delete();

            if (! self::$buggyRevote) {
                $score -= $existing->value;
            }
        }

        $post->votes()->create(['voter' => $voter, 'value' => $value]);
        $post->update(['score' => $score]);
    }
}
