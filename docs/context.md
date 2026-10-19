# The context

The `Context` is the bag threaded through one trail. Every step's `act` and `assert` closures receive it, and it is the single place trail state lives.

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

## Memory

`remember($key, $value)` stores anything and returns the value (so it composes inside an `act` closure). Read it back with `get()`, or with a **typed getter** that fails loudly on the wrong type:

- `instance($key, Model::class)` — returns the object, throwing unless it is an instance of the given class.
- `integer($key)` / `string($key)` — scalar getters.

Typed getters are worth preferring in assertions: a mistyped key surfaces as a clear error rather than a confusing downstream failure.

`push($key, $value)` appends to a remembered list (creating it on first push), and `list($key)` returns it (`[]` when nothing was pushed yet) — handy for accumulating ids created across repeated steps.

## Seeded randomness

This is the contract that makes trails reproducible: **all randomness inside a step must come from the context**, never from `rand()`, `mt_rand()`, `array_rand()`, or `fake()`.

- `pick(array $options)` — a seeded choice from the options. In a shrunk trail's listing the draw names what it picked (`[drew 1 → Order #140]`) for models, enums and scalars; see [Reproducing failures](reproducing-failures.md#automatic-shrinking).
- `randomInt(int $min, int $max)` — a seeded integer in range.
- `randomizer()` — the underlying `\Random\Randomizer` as an escape hatch. Using it marks the execution value-opaque, so those draws are excluded from [value shrinking](reproducing-failures.md); prefer `pick()`/`randomInt()` when you want the shrinker to minimise the value.

### Writing effective generators

`pick()` and `randomInt()` make generation easy, but a uniform pick over *everything* mostly generates actions the app rejects: checking out an item that's already out, nesting an item inside itself, cancelling something that doesn't exist. Each rejected action is a step that ran without testing anything. A journey that picked uniformly from three items once went 31 trails without producing the ordering that exposed a bug. Picking only from the items that were currently valid found it on the first shuffled trail.

- **Pick from what's currently valid.** Query the options that make sense now, rather than everything the journey created, and order them by key so the pick is the same on replay:

    ```php
    $item = $ctx->pick(Item::query()->where('status', 'available')->orderBy('id')->get()->all());
    ```

    When there's nothing valid to pick, say so with [`$ctx->noop()`](steps.md#saying-a-step-had-no-effect-noop), or close the step with a [`when()`](steps.md) precondition, rather than picking something invalid.

- **Pick the second operand from options that differ from the first.** A move, transfer or merge between two picks of the same list is a no-op half the time with two options:

    ```php
    $from = $ctx->pick($boxes);
    $to = $ctx->pick(array_values(array_filter($boxes, fn (Box $box): bool => ! $box->is($from))));
    ```

- **Prefer toggles to uniform choices between states.** "Archive if active, restore if archived" always does something; a uniform pick between archive and restore fails half its picks.
- **Straddle the boundaries that matter.** Draw ranges that include 0, 1 and the limit, and a step beyond it: `randomInt(0, $limit + 1)` rather than `randomInt(1, 10)`. The value shrinker then reports the smallest value that still fails.
- **Check the interesting cases actually occur.** Run with [`RUNABOUT_COVERAGE=1`](observability.md#is-the-shuffle-count-buying-coverage-runabout_coverage) to see how often each step ran and took effect, and which orderings of step pairs never happened. [`requireEffect()`](observability.md#did-the-steps-do-anything-requireeffect) fails a run whose steps never did anything.

## Run history

- `timesRan($step)` — how many completed executions of a step have happened so far this trail.
- `ranBefore($step)` — whether the step has completed before; it is `false` during a step's own first run, which is the key to writing conditional assertions on repeatable steps.

## Deferred cleanup

`defer(fn () => ...)` registers a teardown on the trail's LIFO stack, run in reverse order at the end of the trail and guaranteed to run even on failure. It is the dynamic sibling of a step's `teardown()`. The context also hosts [actors](actors-http.md) and [time travel](time-travel.md), covered on their own pages.
