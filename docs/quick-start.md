# Quick start

Define a journey (this and every example below is lifted from the package's own test suite — a small fictional forum):

```php
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariants;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;

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
                    } catch (RuntimeException) {
                        $ctx->remember('last vote rejected', true);
                    }
                })
                ->assert(function (Context $ctx): void {
                    $post = $ctx->instance('post', Post::class)->refresh();

                    if (in_array($post->status, ['locked', 'archived'], true)) {
                        Assert::assertTrue($ctx->get('last vote rejected'), 'Voting on a locked or archived post must be rejected.');
                    } else {
                        Assert::assertFalse($ctx->get('last vote rejected'), 'Voting on a published post must succeed.');
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
            Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),
        ];
    }
}
```

Run it from any Laravel or Testbench test case:

```php
use Vusys\Runabout\RunsJourneys;

final class PostLifecycleTest extends TestCase
{
    use RunsJourneys;

    public function test_post_lifecycle(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
    }
}
```

## Three things worth noticing

- **`repeatable()` steps assert conditionally.** The same `cast vote` step asserts success on a published post and rejection on a locked one — the shuffler decides which you get, so one step tests both the success path and the guard path. See [Defining steps](steps.md).
- **The context is the only source of randomness.** `$ctx->pick([...])` and `$ctx->randomInt()` draw from the trail's seeded randomizer, which is what makes every trail reproducible. Never reach for `rand()` or `fake()` directly. See [The context](context.md).
- **Invariants run after every step.** The score invariant above doesn't care which step just ran; it recomputes the cached column from source data and objects the moment they disagree. See [Invariants](invariants.md).

## What a failure looks like

Suppose `vote()` has a real bug: replacing an existing vote forgets to subtract the old vote's value from the cached score. Every conventional test stays green, because every conventional test votes once. A shuffled trail that happens to revote catches it immediately:

```
Journey Tests\Journeys\PostLifecycleJourney failed (shuffled, seed 923206350) at step 6 ("cast vote").
Trail:
   1. create community
   2. draft post
   3. publish post
   4. cast vote
   5. cast vote (run 2)
>  6. cast vote (run 3)
Invariant "Post.score matches its source data" violated after step "cast vote": Post 1 has a stale cached score: the column holds 1 but the source data gives 2.
Replay with RUNABOUT_SEED=923206350.
```

`RUNABOUT_SEED=923206350 vendor/bin/phpunit --filter test_post_lifecycle` re-runs that exact trail — same order, same voters, same values — until you've fixed the bug. See [Reproducing failures](reproducing-failures.md) for the full story on seeds, artifacts, and shrinking.

This is not a hypothetical: the package's own test suite plants five bugs of this shape behind toggles in its fixture app and proves that the canonical order misses every one of them while shuffled trails find each. Those tests double as the package's demo.
