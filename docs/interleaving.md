# Interleaving journeys

The bug class nothing else reaches: each participant's journey is individually correct, but their *interaction* leaks state — the classic case being multi-tenant isolation enforced by query convention rather than by the database. Interleave mode merge-shuffles two or more journey instances into one trail:

```php
$this->interleave(new CommunityJourney('alpha'), new CommunityJourney('beta'))
    ->shuffles(15)
    ->run();
```

Each instance keeps its own context — remembered values, run history, actors — while sharing the trail's randomizer and teardown stack, so one seed still replays the whole merged trail. Every instance's invariants run after every step of *any* instance, which is what lets a tenant-isolation invariant declared on the journey police both tenants at once.

The canonical trail — the one that always runs first — is itself an interleaving: round robin across instances by declared position (A's first step, B's first step, A's second, and so on), not each instance's whole journey run back to back. That keeps every instance's own declared order intact while making sure a step guarded by a condition on *another* instance's state (`only read B's records if B has any`) can already be enabled the first time the canonical trail reaches it.

Trail lines carry instance labels, and a violation names both the invariant's instance and the acting step's — here community A's invariant catching community B's very first post:

```
   1. A: found community
   2. A: draft post
   3. A: publish post
   4. A: cast vote
   5. B: found community
>  6. B: draft post
Invariant "A: Community.posts_count matches its source data" violated after step "B: draft post": Community 2 has a stale cached posts_count: the column holds 2 but the source data gives 1.
```

The package's fixture plants exactly this bug — a cached `posts_count` refreshed by a query that forgot its community scope. With one community the scoped and unscoped counts are provably identical, so *no* single-instance trail can ever detect it; the suite asserts that 40 single-instance shuffles stay green while interleaved trails catch it immediately.

`interleave()` accepts journey **instances** (not class-strings), so you can construct each with the state that distinguishes it. [Exhaustive mode](execution-modes.md) is not available for interleaved runs.

## Per-instance environment: `aroundStep()`

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

`aroundStep()` is not interleave-only — it wraps executions in single-journey runs too — but it earns its keep most where several instances share global state.
