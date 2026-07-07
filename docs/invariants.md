# Invariants

An invariant is a check that must hold after **every** step, whatever just ran. It is where you encode the properties a shuffled trail is trying to break: a cached column that must match its source, a quota that must balance, a status column that may only move along legal edges. Hand-write one with `Invariant::make('name', fn (Context $ctx) => ...)`, or configure a built-in from the `Invariants` library.

```php
public function invariants(): array
{
    return [
        // A cached/denormalised column must equal the value recomputed from source data.
        Invariants::cachedColumnMatches(Post::class, 'score', fn (Post $post): int => (int) $post->votes()->sum('value')),

        // A quota column must equal the starting allowance minus actual spend.
        // The allowance is a constant here, or a closure when it differs per row
        // (by plan, tier, tenant): quotaBalances(..., fn ($row): int => $row->plan_allowance, ...).
        Invariants::quotaBalances(Post::class, 'reports_remaining', 2, fn (Post $post): int => $post->reports()->count()),

        // A state column may only move along declared edges.
        Invariants::legalTransitions(Post::class, 'status', [
            'draft' => ['published'],
            'published' => ['locked'],
            'locked' => ['archived'],
        ], initial: ['draft']),

        // Soft-deleted parents must not keep live children.
        Invariants::trashedLeavesNoLiveChildren(Post::class, fn (Post $post): int => $post->votes()->count(), 'votes'),

        // No two rows share a column tuple — what a unique constraint (or a
        // firstOrCreate/dedup path) is meant to guarantee.
        Invariants::uniqueBy(Vote::class, ['post_id', 'voter']),
    ];
}
```

## Built-in invariants

Every built-in is a static factory on `Vusys\Runabout\Invariants` returning an `Invariant`.

### `cachedColumnMatches($model, $column, $expected)`

The cached/denormalised `$column` on every row of `$model` must equal the value recomputed by the `$expected` closure from source data. Catches counter and aggregate drift — a `score`, `posts_count`, or `total` that one write path updates and another forgets. The comparison is deliberately loose so it doesn't trip over driver-specific numeric types.

### `quotaBalances($model, $column, $starting, $spent)`

The quota `$column` must equal the starting allowance minus actual spend. `$starting` is a constant (`2` above) or a per-row closure when the allowance differs by plan, tier, or tenant (`fn ($row) => $row->plan_allowance`). `$spent` recomputes real consumption from source data. Catches quota drift where grant and spend live in different code paths.

### `legalTransitions($model, $column, $transitions, initial: null, stateOf: null)`

A **stateful** invariant: it tracks each row's `$column` across the trail and objects the moment a row jumps between states no declared edge connects. `$transitions` maps each state to the states it may move to; `initial` lists the legal starting states. Handles plain strings and `BackedEnum` values. Pass `stateOf` to derive the state from something other than a raw column.

### `trashedLeavesNoLiveChildren($model, $liveChildren, $description = 'children', $deletedAtColumn = 'deleted_at')`

A soft-deleted parent must keep no live children. `$liveChildren` returns the count of children that are still live for a given parent. Catches soft-delete leaks where trashing a parent orphans rows that should have gone with it.

### `uniqueBy($model, $columns)`

No two rows of `$model` may share the given column tuple — what a unique constraint, or a `firstOrCreate`/dedup path, is meant to guarantee but scattered code can violate under the right ordering.

## Stateful invariants live for one trail

Invariants are collected once per trail, so a stateful invariant like `legalTransitions` lives exactly as long as the trail it watches — it starts fresh each trail and accumulates observations only within it.

## Baseline observation: `fromStart()`

Invariants are checked after every step — but **not before the first one**. If a state invariant watches a row that exists *before* the journey (rather than one created in a step), its first observation is the state the opening step left, so that step's transition is mistaken for the row's initial state.

Add `->fromStart()` to check the invariant once at the trail's start too, so it sees the true baseline:

```php
Invariants::legalTransitions(Post::class, 'status', $edges, initial: ['draft'])->fromStart();
```

## Custom invariants

Anything the built-ins don't cover is a plain closure:

```php
Invariant::make('daily vote bucket matches votes cast today', function (Context $ctx): void {
    // recompute from source data and assert; throw (or fail an assertion) on violation
});
```

The closure receives the context and should throw — a failed PHPUnit assertion is fine — when the property is violated. Custom and built-in invariants mix freely in the same `invariants()` array.
