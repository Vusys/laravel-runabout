# Runabout

Journey testing for Laravel: define the steps of a user journey once, and Runabout executes them in randomized-but-deterministic orders, checking your invariants after every step. It exists to catch the bugs that unit, feature, and browser tests all miss — the ones that only appear when real users do things in an order you never thought to test.

```php
final class PostLifecycleTest extends TestCase
{
    use RunsJourneys;

    public function test_post_lifecycle(): void
    {
        $this->journey(PostLifecycleJourney::class)->shuffles(25)->run();
    }
}
```

One journey definition becomes twenty-six executions: the declared order, then twenty-five seeded shuffles that respect your constraints. When a shuffle fails, the seed reproduces the exact trail.

## The problem

Complex journeys — where each step mutates shared state — are where production bugs live, and they recur as a handful of order-dependent patterns:

- **Replace logic that drifts**: an "update" implemented as delete-then-insert that only balances in the order the developer imagined.
- **Counter and quota drift**: denormalised counters incremented and decremented by different code paths that only agree on the happy path.
- **Cache and aggregate staleness**: recomputation that fires on one mutation path but not another.
- **Unenforced state machines**: a status column guarded by scattered `if`s, where one missing guard lets a row jump between states no edge connects.
- **Soft-delete leaks**: a parent is trashed, its children live on.

A feature test encodes one ordering — the golden path — and never explores another. Runabout explores the others for you, deterministically.

## How it works

You define a **Journey** as a set of **Steps**. Each step has an action, assertions, and constraints. Runabout runs the steps in the declared order once (your journey is also just a readable feature test), then in N seeded random orders, picking at every tick among the steps whose preconditions are currently satisfied. After *every* step it checks the journey's **Invariants** — things that must hold no matter what just happened. Each execution's ordered step list is its **Trail**; all randomness flows from one integer seed, so any trail replays exactly.

## Quick start

```
composer require --dev vusys/laravel-runabout
```

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

Three things worth noticing:

- **`repeatable()` steps assert conditionally.** The same `cast vote` step asserts success on a published post and rejection on a locked one — the shuffler decides which you get, so one step tests both the success path and the guard path.
- **The context is the only source of randomness.** `$ctx->pick([...])` and `$ctx->randomInt()` draw from the trail's seeded randomizer, which is what makes every trail reproducible.
- **Invariants run after every step.** The score invariant above doesn't care which step just ran; it recomputes the cached column from source data and objects the moment they disagree.

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

`RUNABOUT_SEED=923206350 vendor/bin/phpunit --filter test_post_lifecycle` re-runs that exact trail — same order, same voters, same values — until you've fixed the bug.

This is not a hypothetical: the package's own test suite plants five bugs of this shape behind toggles in its fixture app and proves that the canonical order misses every one of them while shuffled trails find each. Those tests double as the package's demo.

## Shrinking a failing trail

A seed reproduces a failing trail, but a repeat-heavy trail that fails on execution 23 hands you 23 steps to read when perhaps four matter. So when a shuffled or repeat-heavy trail fails, Runabout automatically minimises it — removing executions that don't change the outcome — and leads the failure output with the shortest trail that still fails *the same way*:

```
Shrunk from 23 executions to 3 (12 replays):
Journey Tests\Journeys\MaxDealCacheJourney failed (replayed, seed 1351231025) at step 3 ("close largest opportunity").
Trail:
   1. open opportunity
   2. open opportunity (run 2)
>  3. close largest opportunity
Invariant "Account.largest_open_deal matches its source data" violated after step "close largest opportunity": Account 2 has a stale cached largest_open_deal: the column holds 416 but the source data gives 320.
Replay with RUNABOUT_TRAIL='{"seed":1351231025,"steps":[[null,"open opportunity",3],[null,"open opportunity",4],[null,"close largest opportunity",1]]}'.
Replay the full 13-execution trail with RUNABOUT_SEED=1351231025.
```

"The same way" is exact: same exception class plus the invariant's (or failing step's) name, so shrinking never quietly swaps the reported bug for a different one it happened to trip while removing steps. The search is deterministic and budget-capped (`RUNABOUT_SHRINK=0` turns it off, `RUNABOUT_SHRINK_BUDGET` changes the cap).

`RUNABOUT_TRAIL` replays that exact shrunk trail — order and data both — because each execution's random draws depend only on which execution it is, not on what ran before it. That is also what lets shrinking be sound: removing a step never shifts the survivors' draws. The package's `docs/shrinker-benchmarks.md` corpus grades the shrinker against bugs whose minimal counterexample is known in advance.

## Steps

```php
Step::make('score criterion')
    ->act(fn (Context $ctx) => ...)          // do the thing (service call, HTTP request, job dispatch)
    ->assert(fn (Context $ctx) => ...)       // may be called multiple times; runs after the act
    ->assertWhen(                            // conditional assertion, in the spirit of Laravel's when()
        fn (Context $ctx): bool => ...,      //   truth about the observed state
        fn (Context $ctx) => ...,            //   must pass when the condition holds
        fn (Context $ctx) => ...,            //   must pass otherwise — omit it and the step claims nothing
    )
    ->after('start review')                  // sugar: only eligible once these steps have run
    ->when(fn (Context $ctx) => ...)         // raw precondition: eligible only while this is true
    ->repeatable(max: 5)                     // may run again after completing (null/omitted max = unbounded)
    ->weight(3)                              // picked 3x as often as a weight-1 step when both are eligible
    ->teardown(fn (Context $ctx) => ...);    // cleanup, registered per execution
```

Because a shuffled trail decides what state a step observes, assertions on repeatable steps are often conditional: voting on a published post must succeed, voting on a locked one must be rejected, and the shuffler picks which you get. `assertWhen()` keeps that as three small declarative closures instead of an `if`/`else` inside one assert body. Assertions still run in the order they were declared, and `assert()` and `assertWhen()` mix freely on one step — genuinely n-way logic is usually still clearer as a single `assert()` with control flow.

The shuffled runner picks randomly among *currently eligible* steps until every step has run at least once. A journey whose constraints can strand it (no step eligible but some never ran) fails loudly as a deadlock, naming the steps that never ran; a journey that repeats forever is cut off as a runaway.

Teardowns exist for non-database global state — frozen time, config overrides, fakes, static caches. They run at the end of the trail in reverse execution order (a repeatable step run three times registers three teardowns), they are guaranteed to run on failure, and a teardown that itself throws never masks the primary failure. Inside an action, `$ctx->defer(fn () => ...)` registers cleanup dynamically.

## The context

The `Context` is the bag threaded through one trail:

```php
$ctx->remember('post', $post);               // store anything; returns the value
$ctx->get('post');                           // mixed
$ctx->instance('post', Post::class);         // typed: throws unless it holds a Post
$ctx->integer('post id');                    // typed getters for scalars
$ctx->string('reporter');
$ctx->has('post');
$ctx->forget('post');

$ctx->push('created posts', $post->id);      // append to a remembered list, starting it when absent
$ctx->list('created posts');                 // that list — [] when nothing was pushed yet

$ctx->pick(['ana', 'ben', 'cai']);           // seeded choice — never use rand()/fake() directly
$ctx->randomInt(1, 10);                      // seeded int
$ctx->randomizer();                          // the underlying \Random\Randomizer

$ctx->timesRan('cast vote');                 // completed executions of a step so far
$ctx->ranBefore('cast vote');                // false during a step's own first run — the key to conditional assertions

$ctx->defer(fn () => ...);                   // teardown stack, LIFO at end of trail
```

### Actors and HTTP

Journeys can drive the app over HTTP with named, authenticated actors:

```php
Step::make('sign up the voters')
    ->act(function (Context $ctx): void {
        foreach (['ana', 'ben', 'cai'] as $name) {
            $ctx->actingAs(User::query()->create(['name' => $name]), $name);
        }
    }),

Step::make('cast vote')
    ->after('publish post')
    ->repeatable()
    ->act(fn (Context $ctx) => $ctx->as($ctx->pick(['ana', 'ben', 'cai']))
        ->postJson(sprintf('/posts/%d/vote', $ctx->integer('post id')), ['value' => $ctx->pick([1, -1])]))
    ->assertWhen(
        fn (Context $ctx): bool => Post::query()->findOrFail($ctx->integer('post id'))->status === 'locked',
        fn (Context $ctx) => $ctx->lastResponse()->assertStatus(409),
        fn (Context $ctx) => $ctx->lastResponse()->assertCreated(),
    ),
```

Every request made through `$ctx->as('name')` is authenticated as that actor's user, so a journey can hop freely between participants. Actors proxy the full set of test HTTP verbs (`get`, `getJson`, `post`, `postJson`, `put`, `putJson`, `patch`, `patchJson`, `delete`, `deleteJson`), and `$ctx->lastResponse()` holds the most recent `TestResponse`.

When the participants are fixed for the whole run, declare them once with `actors()` instead of a sign-up step — Runabout registers them on every trail's context for you (actors live on the per-trail context, so they otherwise have to be re-registered each trail):

```php
final class ReviewJourney extends Journey
{
    public function __construct(private User $manager, private User $agent) {}

    public function actors(): array
    {
        return ['manager' => $this->manager, 'agent' => $this->agent];
    }

    // steps() can call $ctx->as('manager') / $ctx->as('agent') straight away.
}
```

The users must exist before the run (create them in the test and pass them in); anything created inside a trail is rolled back before the next one, so register those with `$ctx->actingAs()` in a step as the voters above do.

If your app carries tenancy (or anything else) in the session, attach it to the actor and it rides along with every request that actor makes — driving the full middleware stack, no per-request session juggling:

```php
$ctx->actingAs($user, 'agent', ['current-tenant-id' => $tenant->id]);   // persists for all of this actor's requests
$ctx->as('agent')->withSession(['impersonating' => 42])->postJson(...);  // layered on for one request
```

### Time travel

```php
Step::make('a new day dawns')
    ->act(fn (Context $ctx) => $ctx->travel('+1 day'));
```

`travelTo($moment)`, `travel($modifier)`, and `travelBack()` wrap Laravel's test clock, and the clock is unwound automatically at the end of every trail — even a failing one — so frozen time never leaks into the next trail or test. Steps like the one above turn time itself into a shuffleable event: any logic bucketed by day (counters, digests, rate limits) gets exercised across the boundary in some trails and within it in others.

## Invariants

An invariant is a check that must hold after **every** step. Hand-write them with `Invariant::make('name', fn () => ...)`, or configure a built-in:

```php
public function invariants(): array
{
    return [
        // A cached/denormalised column must equal the value recomputed from source data.
        Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),

        // A quota column must equal the starting allowance minus actual spend.
        Invariants::quotaBalances(Post::class, 'reports_remaining', 2, fn (Post $post): int => $post->reports()->count()),

        // A state column may only move along declared edges.
        Invariants::legalTransitions(Post::class, 'status', [
            'draft' => ['published'],
            'published' => ['locked'],
            'locked' => ['archived'],
        ], initial: ['draft']),

        // Soft-deleted parents must not keep live children.
        Invariants::trashedLeavesNoLiveChildren(Post::class, fn (Post $post): int => $post->votes()->count(), 'votes'),
    ];
}
```

`legalTransitions` is stateful: it tracks each row's state across the trail and objects the moment a row jumps between states no declared edge connects. Invariants are collected once per trail, so a stateful invariant lives exactly as long as the trail it watches.

## Execution modes

```php
$this->journey(PostLifecycleJourney::class)
    ->shuffles(25)          // canonical order + 25 seeded shuffles (default 10)
    ->run();

$this->journey(PostLifecycleJourney::class)
    ->repeatHeavy()         // bias the picker toward repeatable steps (default 5x)
    ->shuffles(15)
    ->run();

$this->journey(SmallJourney::class)
    ->exhaustive()          // run every valid ordering; refuses above 720 orderings
    ->run();

$this->journey(PostLifecycleJourney::class)
    ->seed(923206350)       // replay one exact trail
    ->run();
```

Repeat-heavy mode is the idempotency hunter: re-running steps is how replace-logic, counter, and quota bugs surface, and the bias finds them in far fewer trails than uniform shuffling (the package's test suite asserts exactly that). Exhaustive mode suits small journeys where you'd rather prove every ordering than sample.

## Interleaving journeys

The bug class nothing else reaches: each participant's journey is individually correct, but their *interaction* leaks state — the classic case being multi-tenant isolation enforced by query convention rather than by the database. Interleave mode merge-shuffles two or more journey instances into one trail:

```php
$this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))
    ->shuffles(15)
    ->run();
```

Each instance keeps its own context — remembered values, run history, actors — while sharing the trail's randomizer and teardown stack, so one seed still replays the whole merged trail. Every instance's invariants run after every step of *any* instance, which is what lets a tenant-isolation invariant declared on the journey police both tenants at once. Trail lines carry instance labels, and a violation names both the invariant's instance and the acting step's — here community A's invariant catching community B's very first post:

```
   1. A: found community
   2. A: draft post
   3. A: publish post
   4. A: cast vote
   5. B: found community
>  6. B: draft post
Invariant "A: Community.posts_count matches its source data" violated after step "B: draft post": Community 2 has a stale cached posts_count: the column holds 2 but the source data gives 1.
```

The package's fixture plants exactly this bug — a cached `posts_count` refreshed by a query that forgot its community scope. With one community the scoped and unscoped counts are provably identical, so *no* single-instance trail can ever detect it; the suite asserts that 40 single-instance shuffles stay green while interleaved trails catch it immediately. The full design rationale lives in [docs/interleave-design.md](docs/interleave-design.md).

### Per-instance environment: `aroundStep()`

Some apps carry "who is acting" in global state beyond the auth guard — session-keyed tenancy is the classic case. Interleaved instances share that state, and whose turn it is changes step to step, so it can't be established once per trail. Override `aroundStep()` on the journey and it wraps every execution belonging to that instance — each step *and* each check of that instance's invariants (which run after other instances' steps too):

```php
class TenantJourney extends Journey
{
    public function __construct(private readonly Tenant $tenant, private readonly User $user) {}

    public function aroundStep(Closure $execution, Context $ctx): void
    {
        Auth::setUser($this->user);
        session()->put('current-tenant-id', $this->tenant->id);

        $execution();
    }

    // ...steps() and invariants() as usual, free of tenancy plumbing.
}
```

Declaring it once on the journey closes a subtle hole: a per-act helper that someone forgets on one step doesn't error — the act silently runs as whichever tenant acted last, producing consistent-looking data under the wrong tenant that no invariant can flag. The hook can't be forgotten per step, and a wrapper that fails to call `$execution()` is rejected loudly.

## Database reset between trails

Each trail runs against fresh state. The default wraps every trail in a transaction on the default connection and rolls it back. When the code under test commits or manages transactions itself, opt into truncation:

```php
$this->journey(PostLifecycleJourney::class)
    ->resetByTruncating('votes', 'posts', 'communities', 'users')
    ->run();
```

When a journey writes across more than one store, `resetConnections()` rolls back a transaction on each named connection, and `resetExternal()` runs a cleanup for anything a transaction can't undo (a Mongo or Elasticsearch wipe, a cache flush). The two compose — and `resetExternal()` on its own still transacts the default connection:

```php
$this->journey(AnalyticsJourney::class)
    ->resetConnections('mysql', 'analytics')          // roll back both SQL connections
    ->resetExternal(fn () => $this->wipeMongoContacts()) // then clean the document store
    ->run();
```

For anything more bespoke, supply the whole wrapper yourself:

```php
$this->journey(PostLifecycleJourney::class)
    ->resetWith(function (Closure $trail): void {
        // begin, run, restore — whatever your app needs
        $trail();
    })
    ->run();
```

You can also override `wrapTrail()` on your test case to change the default for every journey it runs.

Whatever the reset, a store the trail seeds must be seeded *inside a step*, not once in `setUp()`: the reset runs after every trail, so state established before the run is gone after the first one. (A step that re-creates it when absent is the usual shape.)

## Watching the trails

A passing run is silent by default. To see what the shuffler actually explored, set `RUNABOUT_VERBOSE=1` and every completed trail is printed to stderr as it finishes:

```
[PostLifecycleJourney] trail 3/16 (shuffled, seed 923206352)
   1. create community
   2. draft post
   3. report post
   4. publish post
   5. cast vote
   6. cast vote (run 2)
   ...
```

For anything programmatic — collecting trails, counting how often a step ran, feeding a coverage report — register a callback instead; it receives each completed `Trail` in every mode (failing trails already describe themselves in the failure output):

```php
$this->journey(PostLifecycleJourney::class)
    ->shuffles(25)
    ->onTrail(fn (Trail $trail) => $log[] = $trail->steps())
    ->run();
```

### Is the shuffle count buying coverage?

Verbose output shows individual trails; the question it can't answer is the aggregate one — across all those trails, what did the run actually explore? Set `RUNABOUT_COVERAGE=1` and a green run ends with a summary on stderr:

```
[PostLifecycleJourney] trail coverage
51 trails (1 canonical, 50 shuffled), 51 distinct orderings
Step executions:
  create community  51 runs in 51/51 trails
  draft post        51 runs in 51/51 trails
  publish post      51 runs in 51/51 trails
  a new day dawns   51 runs in 51/51 trails
  cast vote         111 runs in 51/51 trails
  report post       154 runs in 51/51 trails
  lock post         51 runs in 51/51 trails
  archive post      51 runs in 51/51 trails
  remove post       51 runs in 51/51 trails
Orderings of step pairs never observed (18 of 72):
  "draft post" before "create community"
  "publish post" before "create community"
  "publish post" before "draft post"
  ...
```

Read the never-observed list against your constraints. Every pair above is impossible by design — each names a step running before its own prerequisite — so this run has genuinely explored every ordering its constraints allow. An *unconstrained* pair on that list is the finding: the shuffles never tried that ordering, and a bug hiding behind it is invisible at this shuffle count. Distinct orderings tell the same story from above — 51 trails producing 51 distinct orderings means the seeds aren't wasting trails re-walking the same path.

The aggregation is a plain object, `TrailCoverage`, so you can also collect it yourself — across several runs, or to assert coverage properties in the test:

```php
$coverage = new TrailCoverage;

$this->journey(PostLifecycleJourney::class)
    ->shuffles(50)
    ->onTrail($coverage->record(...))
    ->run();

$this->assertSame(0, $coverage->timesBefore('publish post', 'draft post'));
$this->assertGreaterThan(40, $coverage->distinctOrderings());
```

## Environment variables

- `RUNABOUT_SEED=923206350` — replay one exact shuffled trail. Every failure message prints this line for you.
- `RUNABOUT_TRAIL='{"seed":...,"steps":[...]}'` — replay one exact trail from an artifact (a seed plus an ordered token list), including a shrunk or repeat-heavy trail a bare seed cannot reproduce. `RUNABOUT_TRAIL=@path.json` reads it from a file. Printed for you under every shrunk failure.
- `RUNABOUT_SHRINK=0` — turn off automatic shrinking of failing trails. `RUNABOUT_SHRINK_BUDGET=200` caps how many candidate replays the shrinker may run.
- `RUNABOUT_RANDOMIZE=1` — explore fresh random seeds instead of the stable derived ones. Meant for a nightly CI job that hunts orderings the fixed seeds never visit; any failure it finds prints its seed, so it replays exactly.
- `RUNABOUT_VERBOSE=1` — print every completed trail to stderr as it runs.
- `RUNABOUT_COVERAGE=1` — print an aggregate coverage summary to stderr when a run finishes: executions per step, distinct orderings, and the step-pair orderings no trail explored.

By default seeds are derived deterministically from the journey class and trail index, so ordinary CI runs are stable from commit to commit.

## Requirements

- PHP 8.3+
- Laravel 11 or 12

## License

MIT. See [LICENSE](LICENSE).
