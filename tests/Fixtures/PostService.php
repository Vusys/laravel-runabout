<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Support\Facades\Date;
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

    /**
     * Planted bug: the votes_today bucket resets lazily when the day rolls
     * over — the buggy version stamps today's date but forgets to reset the
     * count, so yesterday's votes bleed into today's bucket. Only a trail
     * that votes, crosses a day boundary, then votes again can catch it.
     */
    public static bool $buggyStaleBucket = false;

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

        $today = Date::today()->toDateString();

        $score = $post->score + $value;

        $bucketIsFresh = $post->votes_today_date?->toDateString() === $today;
        $bucket = ($bucketIsFresh || self::$buggyStaleBucket) ? $post->votes_today : 0;

        $existing = $post->votes()->where('voter', $voter)->first();

        if ($existing !== null) {
            $existing->delete();

            if (! self::$buggyRevote) {
                $score -= $existing->value;
            }

            if ($existing->cast_on->toDateString() === $today) {
                $bucket--;
            }
        }

        $post->votes()->create(['voter' => $voter, 'value' => $value, 'cast_on' => $today]);
        $post->update(['score' => $score, 'votes_today' => $bucket + 1, 'votes_today_date' => $today]);
    }
}
