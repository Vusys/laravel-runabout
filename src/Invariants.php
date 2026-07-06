<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Database\Eloquent\Model;
use RuntimeException;

/**
 * Factories for the built-in invariant library. Each returns an ordinary
 * Invariant, so built-ins and hand-written invariants mix freely in
 * Journey::invariants(). Each targets a bug class that only unusual step
 * orderings expose: stale caches, quota drift, illegal state transitions,
 * soft-deleted parents with live children.
 */
final class Invariants
{
    /**
     * Every row's cached column must equal the value recomputed from source
     * data: denormalised counters, cached sums, materialised flags.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  Closure(TModel): mixed  $expected  Recompute the value from source data.
     */
    public static function cachedColumnMatches(string $model, string $column, Closure $expected): Invariant
    {
        return Invariant::make(
            sprintf('%s.%s matches its source data', class_basename($model), $column),
            function () use ($model, $column, $expected): void {
                foreach (self::typed($model, (new $model)->newQuery()->get()) as $row) {
                    $cached = $row->getAttribute($column);
                    $recomputed = $expected($row);

                    // Loose comparison on purpose: database drivers disagree
                    // about numeric types (aggregate functions often return
                    // strings), and that difference is never the bug.
                    if ($recomputed != $cached) {
                        throw new RuntimeException(sprintf(
                            '%s %s has a stale cached %s: the column holds %s but the source data gives %s.',
                            class_basename($model),
                            self::key($row),
                            $column,
                            var_export($cached, true),
                            var_export($recomputed, true),
                        ));
                    }
                }
            },
        );
    }

    /**
     * A quota column must always equal the starting allowance minus what has
     * actually been spent — catches double-charging and missed refunds.
     *
     * The starting allowance may be a constant shared by every row, or a
     * closure computing it per row — real quotas differ by plan, tier, or
     * tenant, so a single constant rarely fits.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  int|Closure(TModel): int  $starting  The allowance before any spend: a constant, or computed per row.
     * @param  Closure(TModel): int  $spent  Count what has actually been consumed.
     */
    public static function quotaBalances(string $model, string $column, int|Closure $starting, Closure $spent): Invariant
    {
        return Invariant::make(
            sprintf('%s.%s balances against its quota', class_basename($model), $column),
            function () use ($model, $column, $starting, $spent): void {
                foreach (self::typed($model, (new $model)->newQuery()->get()) as $row) {
                    $remaining = $row->getAttribute($column);
                    $allowance = $starting instanceof Closure ? $starting($row) : $starting;
                    $expected = $allowance - $spent($row);

                    if ($expected != $remaining) {
                        throw new RuntimeException(sprintf(
                            '%s %s\'s quota drifted: %s holds %s, but a starting quota of %d minus actual spend leaves %d.',
                            class_basename($model),
                            self::key($row),
                            $column,
                            var_export($remaining, true),
                            $allowance,
                            $expected,
                        ));
                    }
                }
            },
        );
    }

    /**
     * A string state column may only move along declared transitions. The
     * invariant tracks each row's state across the trail and objects the
     * moment a row jumps between states no edge connects — the safety net
     * for state machines enforced by scattered if-guards.
     *
     * States are read from $column and must resolve to a string. A native
     * BackedEnum is coerced to its ->value automatically; for a cast value
     * object (e.g. a spatie ModelState) pass $stateOf to map a row to its
     * state string — that keeps this helper free of any state-cast dependency.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  array<string, list<string>>  $transitions  Allowed edges: from-state => list of to-states.
     * @param  list<string>|null  $initial  States a row may first be observed in (null: any).
     * @param  (Closure(TModel): (string|\BackedEnum))|null  $stateOf  Maps a row to its state; defaults to reading $column.
     */
    public static function legalTransitions(string $model, string $column, array $transitions, ?array $initial = null, ?Closure $stateOf = null): Invariant
    {
        /** @var array<string, string> $seen */
        $seen = [];

        return Invariant::make(
            sprintf('%s.%s only makes legal transitions', class_basename($model), $column),
            function () use ($model, $column, $transitions, $initial, $stateOf, &$seen): void {
                foreach (self::typed($model, (new $model)->newQuery()->get()) as $row) {
                    $key = self::key($row);
                    $raw = $stateOf instanceof Closure ? $stateOf($row) : $row->getAttribute($column);

                    $state = match (true) {
                        is_string($raw) => $raw,
                        $raw instanceof \BackedEnum => (string) $raw->value,
                        default => throw new RuntimeException(sprintf(
                            '%s %s\'s %s holds %s; a state column must hold strings or a BackedEnum, or pass a stateOf closure to map it.',
                            class_basename($model),
                            $key,
                            $column,
                            get_debug_type($raw),
                        )),
                    };

                    if (! isset($seen[$key])) {
                        if ($initial !== null && ! in_array($state, $initial, true)) {
                            throw new RuntimeException(sprintf(
                                '%s %s appeared in state "%s", which is not a legal initial state (%s). If the row exists before the journey, observe it in a leading step so its initial state is recorded before any step transitions it.',
                                class_basename($model),
                                $key,
                                $state,
                                implode(', ', $initial),
                            ));
                        }
                    } elseif ($seen[$key] !== $state && ! in_array($state, $transitions[$seen[$key]] ?? [], true)) {
                        throw new RuntimeException(sprintf(
                            '%s %s made an illegal %s transition: "%s" -> "%s".',
                            class_basename($model),
                            $key,
                            $column,
                            $seen[$key],
                            $state,
                        ));
                    }

                    $seen[$key] = $state;
                }
            },
        );
    }

    /**
     * Soft-deleted rows must not keep live children — the classic leak when a
     * delete-and-cascade is only cascaded on the happy path.
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model  A model using soft deletes.
     * @param  Closure(TModel): int  $liveChildren  Count the children still active for a trashed row.
     */
    public static function trashedLeavesNoLiveChildren(string $model, Closure $liveChildren, string $childrenDescription = 'children', string $deletedAtColumn = 'deleted_at'): Invariant
    {
        return Invariant::make(
            sprintf('trashed %s rows keep no live %s', class_basename($model), $childrenDescription),
            function () use ($model, $liveChildren, $childrenDescription, $deletedAtColumn): void {
                $trashed = self::typed($model, (new $model)->newQuery()
                    ->withoutGlobalScopes()
                    ->whereNotNull($deletedAtColumn)
                    ->get());

                foreach ($trashed as $row) {
                    $count = $liveChildren($row);

                    if ($count > 0) {
                        throw new RuntimeException(sprintf(
                            '%s %s is soft-deleted but still has %d live %s.',
                            class_basename($model),
                            self::key($row),
                            $count,
                            $childrenDescription,
                        ));
                    }
                }
            },
        );
    }

    /**
     * No two rows may share the given column tuple — the guarantee a unique
     * constraint (or a firstOrCreate / dedup path) is meant to provide. Catches
     * duplicate rows a missing or wrongly-scoped key lets through: "at most one
     * metric per report", "one leaderboard period per (tenant, start)",
     * "one acknowledgement per item". Reads all rows (default scope, so
     * soft-deleted rows are excluded and may legitimately repeat a live key).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  non-empty-list<string>  $columns
     */
    public static function uniqueBy(string $model, array $columns): Invariant
    {
        return Invariant::make(
            sprintf('%s rows are unique by (%s)', class_basename($model), implode(', ', $columns)),
            function () use ($model, $columns): void {
                /** @var array<string, string> $seen */
                $seen = [];

                foreach (self::typed($model, (new $model)->newQuery()->get()) as $row) {
                    $values = array_map(fn (string $column): string => var_export($row->getAttribute($column), true), $columns);
                    $key = implode('|', $values);

                    if (isset($seen[$key])) {
                        throw new RuntimeException(sprintf(
                            '%s rows %s and %s share (%s) = (%s).',
                            class_basename($model),
                            $seen[$key],
                            self::key($row),
                            implode(', ', $columns),
                            implode(', ', $values),
                        ));
                    }

                    $seen[$key] = self::key($row);
                }
            },
        );
    }

    /**
     * PHPStan cannot carry a template type through Builder<static>, so rows
     * come back typed as the base Model; the instanceof check restores the
     * concrete type (and is a runtime no-op).
     *
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $model
     * @param  iterable<Model>  $rows
     * @return list<TModel>
     */
    private static function typed(string $model, iterable $rows): array
    {
        $typed = [];

        foreach ($rows as $row) {
            if ($row instanceof $model) {
                $typed[] = $row;
            }
        }

        return $typed;
    }

    private static function key(Model $row): string
    {
        $key = $row->getKey();

        return is_scalar($key) ? (string) $key : get_debug_type($key);
    }
}
