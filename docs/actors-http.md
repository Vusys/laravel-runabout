# Actors & HTTP

Journeys can drive the app over HTTP with named, authenticated **actors**, so a trail exercises your real routes, middleware, and controllers rather than calling services directly.

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

## Registering and switching actors

- `$ctx->actingAs($user, 'name', $session = [])` — register a named actor authenticated as `$user`, optionally with session state. Returns the `Actor`.
- `$ctx->as('name')` — get a previously registered actor.
- `$ctx->lastResponse()` — the most recent `TestResponse` returned by any actor.

Every request made through `$ctx->as('name')` is authenticated as that actor's user, so a journey can hop freely between participants. Actors proxy the full set of test HTTP verbs, each returning a `TestResponse`:

`get`, `getJson`, `post`, `postJson`, `put`, `putJson`, `patch`, `patchJson`, `delete`, `deleteJson`.

## Declaring fixed participants with `actors()`

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

The users declared in `actors()` must exist **before** the run (create them in the test and pass them in). Anything created *inside* a trail is rolled back before the next one, so register those with `$ctx->actingAs()` in a step — as the voters above do.

## Carrying session / tenancy state

If your app carries tenancy (or anything else) in the session, attach it to the actor and it rides along with every request that actor makes — driving the full middleware stack, no per-request session juggling:

```php
$ctx->actingAs($user, 'agent', ['current-tenant-id' => $tenant->id]);   // persists for all of this actor's requests
$ctx->as('agent')->withSession(['impersonating' => 42])->postJson(...);  // layered on for one request
```

`withSession()` returns a copy of the actor with the extra session data merged, for one request, leaving the actor's baseline session intact.

For state that lives *outside* the auth guard and session — and especially in [interleaved journeys](interleaving.md), where whose turn it is changes step to step — see the `aroundStep()` hook.
