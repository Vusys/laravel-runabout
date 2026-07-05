<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Support\Facades\Date;
use RuntimeException;
use Vusys\Runabout\Tests\Fixtures\Models\Community;
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

    /**
     * Planted bug: archive() forgets its status guard, so any post can be
     * archived from any state. The canonical order archives a locked post
     * (a legal transition), so only shuffles that archive early observe an
     * illegal jump like draft -> archived.
     */
    public static bool $buggyArchiveGuard = false;

    /**
     * Planted bug: vote() forgets to check whether the post has been removed
     * (soft-deleted). remove() cleans up existing votes, so only a trail that
     * removes the post and then votes again leaves an orphaned live vote.
     */
    public static bool $buggyVoteOnRemoved = false;

    /**
     * Planted bug: replacing an existing report charges the per-post quota
     * again instead of being free. The canonical order reports once, so only
     * shuffled repeats by the same reporter drain the quota.
     */
    public static bool $buggyReportQuota = false;

    /**
     * Planted bug: refreshing a community's cached posts_count uses a query
     * that forgot its community scope, counting every community's posts.
     * With a single community the scoped and unscoped counts are identical,
     * so no single-instance trail can detect it — only a trail where a
     * second community's journey interleaves.
     */
    public static bool $buggyGlobalPostCount = false;

    public static function reset(): void
    {
        self::$buggyRevote = false;
        self::$buggyStaleBucket = false;
        self::$buggyArchiveGuard = false;
        self::$buggyVoteOnRemoved = false;
        self::$buggyReportQuota = false;
        self::$buggyGlobalPostCount = false;
    }

    public function draft(Community $community, string $title): Post
    {
        $post = $community->posts()->create(['title' => $title]);

        $count = self::$buggyGlobalPostCount
            ? Post::query()->count()
            : $community->posts()->count();

        $community->update(['posts_count' => $count]);

        return $post;
    }

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

    public function archive(Post $post): void
    {
        if (! self::$buggyArchiveGuard && $post->status !== 'locked') {
            throw new RuntimeException('Only locked posts can be archived.');
        }

        $post->update(['status' => 'archived']);
    }

    /** Soft-delete the post and cascade to its live votes and reports. */
    public function remove(Post $post): void
    {
        $post->votes()->delete();
        $post->reports()->delete();
        $post->delete();
    }

    public function vote(Post $post, string $voter, int $value): void
    {
        if (! self::$buggyVoteOnRemoved && $post->trashed()) {
            throw new RuntimeException('Cannot vote on a removed post.');
        }

        if ($post->status === 'locked') {
            throw new PostLockedException('Post is locked.');
        }

        if ($post->status === 'archived') {
            throw new PostLockedException('Post is archived.');
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

    public function report(Post $post, string $reporter): void
    {
        if ($post->trashed()) {
            throw new RuntimeException('Cannot report a removed post.');
        }

        if ($post->status === 'draft') {
            throw new RuntimeException('Cannot report a draft.');
        }

        $existing = $post->reports()->where('reporter', $reporter)->first();

        if ($existing === null && $post->reports_remaining <= 0) {
            throw new RuntimeException('Report quota exhausted for this post.');
        }

        $remaining = $post->reports_remaining;

        if ($existing !== null) {
            $existing->delete();

            if (self::$buggyReportQuota) {
                $remaining--; // Replacing a report double-charges the quota.
            }
        } else {
            $remaining--;
        }

        $post->reports()->create(['reporter' => $reporter]);
        $post->update(['reports_remaining' => $remaining]);
    }
}
