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

- `$ctx->actingAs($user, 'name', $session = [], guard: null, headers: [])` — register a named actor authenticated as `$user`, optionally with session state, [default headers](#default-headers), and on a [named guard](#apps-with-several-guards) or a list of them. Returns the `Actor`.
- `$ctx->actingWithToken($user, $plainTextToken, 'name')` — register an actor that authenticates only by sending a [real access token](#real-access-tokens).
- `$ctx->as('name')` — get a previously registered actor.
- `$ctx->lastResponse()` — the most recent `TestResponse` returned by any actor in a step. Requests an invariant makes are observations, so they don't replace it.

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

## Apps with several guards

By default an actor logs in on the default guard. For an app with several, such as `web` for the storefront and `admin` for the back office, name the guard:

```php
public function actors(): array
{
    return [
        'shopper' => $this->customer,           // the default guard
        'admin' => [$this->adminUser, 'admin'],  // the admin guard
    ];
}

$ctx->actingAs($user, 'late admin', guard: 'admin');  // the same, mid-trail
```

Runabout sets a named-guard actor on that guard only. Laravel's `actingAs($user, 'admin')` would also make `admin` the default guard for the rest of the test. Storefront code that calls `auth()->user()` would then see the admin as the current customer. Routes pick their guard through middleware (`auth:admin`), as they do in production.

Once a named guard is in play, every actor request also starts the way a production request does:
- Runabout resets the default guard to the configured one. Laravel's `auth:admin` middleware switches the default guard, and the test app outlives the request.
- Runabout logs out every other actor's guard. In production each actor has its own session, so an admin request does not also arrive logged in as the last shopper.

Journeys whose actors all use the default guard behave as before.

An actor can log in on several guards at once, for a user of both the UI (a session guard) and the API (a token guard). It is authenticated on each guard listed before every request, through `journeyAuthenticate()` like a single guard:

```php
return ['admin' => [$this->adminUser, ['web', 'api']]];

$ctx->actingAs($user, 'admin', guard: ['web', 'api']);  // the same, mid-trail
```

### Real access tokens

When a token guard checks the session guard first (Sanctum's default `guard => ['web']`), an actor logged in with `actingAs()` makes it ignore an explicit `Authorization: Bearer` header. To exercise a real token, with its abilities, per-token tenancy and expiry, register the actor with `actingWithToken()`. It sends the token as a bearer token with every request, and logs every guard out first, so no other actor's login shadows it:

```php
$token = $user->createToken('integration', ['orders:read'])->plainTextToken;

$ctx->actingWithToken($user, $token, 'integration')->getJson('/api/orders')->assertOk();
```

`Actor::user()` still returns `$user`.

### Token guards

Setting the user on a token guard (Sanctum, Passport) attaches no access token, so token abilities and scopes, and anything the app reads off the current token (a tenant or team id), are missing. Override `journeyAuthenticate()` on the test case to log actors in your own way. It's called before every actor request, with the guard the actor uses (`null` for the default guard), and by default logs in with `actingAs()` on the default guard and `setUser()` on a named one:

```php
use Illuminate\Contracts\Auth\Authenticatable;
use Laravel\Sanctum\Sanctum;

protected function journeyAuthenticate(Authenticatable $user, ?string $guard): void
{
    $guard === 'sanctum'
        ? Sanctum::actingAs($user, ['orders:write'], 'sanctum')
        : parent::journeyAuthenticate($user, $guard);
}
```

For Passport, `Passport::actingAs($user, ['orders:write'], 'api')` attaches scopes the same way. `parent::` reaches the default when `RunsJourneys` is used on a base test case. On a test case that uses the trait directly, alias the default instead: `use RunsJourneys { journeyAuthenticate as defaultJourneyAuthenticate; }`. Both helpers also make their guard the default, which Runabout undoes before the request is sent.

## Reloading users before each request

An actor holds one user instance for the whole run, as Laravel's `actingAs()` does. In production each request loads the user from the session afresh. So these go stale:

- relations loaded on the instance
- caches the app keeps on the model
- attributes another actor has since changed

Every trail and shrink replay also shares the instance, because the per-trail rollback resets rows, not objects in memory.

This matters most in journeys, because actors change each other's state over many requests. For example, an admin grants a member's role a permission. The app still checks the member's next request against the permission list it cached on the instance before the grant. Call `freshActors()` to reload the user before every actor request:

```php
$this->journey(PermissionsJourney::class)->freshActors()->run();
```

- Runabout reloads a saved Eloquent user with `fresh()`, at one query per request. If the user no longer exists, the actor sends its requests as a guest, as production would.
- Runabout authenticates an unsaved user, or one that isn't an Eloquent model, as it is.
- `Actor::user()` still returns the instance that you registered the actor with.

It is off by default, so existing journeys keep the outcomes they had. It can change a journey's outcome, never its trails.

## Isolating each request

Test requests share one application instance, so request-scoped state outlives the request that set it: Laravel `Context` (`Context::add()`, hidden context), scoped container bindings (`$app->scoped(...)`), and anything the app keeps in them. In production each request starts with both empty. In a journey, a value one request sets is still there for every later request in the trail, and for later trails too. For example, an endpoint that marks its request as a bulk operation, so a listener skips per-item notifications, silently changes every request after it.

Call `isolateRequests()` to clear that state before every actor request:

```php
$this->journey(SomeJourney::class)->isolateRequests()->run();
```

- Before each actor request, Laravel `Context` is flushed and scoped container instances are forgotten.
- It also reloads the actor's user, as [`freshActors()`](#reloading-users-before-each-request) does, so each actor request behaves like a fresh production request.
- State a step sets directly (outside a request) is cleared before the step's next request too. If a journey sets `Context` in `aroundStep()` to emulate middleware, leave this off.
- It's off by default, so existing outcomes don't change.

## Driving server-rendered forms

When a step drives a server-rendered form with `post()`, `put()` or `patch()`, it's tempting to send only the fields the step cares about. A real form posts every field back, pre-filled with its current value, and many controllers treat a missing field as "clear it". A partial payload then produces a false bug: a field the journey's own request wiped looks like the app losing data.

- **Send what the real form sends**, including the fields the step isn't changing, with their current values. Building the payload from the model keeps it honest:

    ```php
    $ticket = $ctx->instance('ticket', Ticket::class)->fresh();

    $ctx->as('agent')->put("/tickets/{$ticket->id}", [
        ...$ticket->only(['title', 'description', 'priority', 'assignee_id']),
        'status' => 'resolved',
    ]);
    ```

- **Mirror checkbox semantics.** A browser omits an unticked checkbox entirely and sends the box's value for a ticked one, so send `'notify' => '1'` or leave the key out, rather than `'notify' => false`. If the form pairs the checkbox with a hidden field (the usual way to post a 0), send that instead.
- **Include the hidden fields the form always posts**, such as a redirect target, a `_method` override, or a version or lock token.
- **Assert on state after a form post, not on the redirect.** Most form endpoints redirect whatever happens, back to the form with errors or on to the next page, so `assertRedirect()` passes on a rejected submission. Check the database, or the [last response](reproducing-failures.md#when-a-steps-request-was-rejected)'s session errors with `assertSessionHasNoErrors()`. To count rejected submissions as steps that had [no effect](steps.md#saying-a-step-had-no-effect-noop), override `journeyIsRejected()`:

    ```php
    protected function journeyIsRejected(TestResponse $response): bool
    {
        return $response->isRedirect() && session()->has('errors');
    }
    ```

## Carrying session / tenancy state

If your app carries tenancy (or anything else) in the session, attach it to the actor and it rides along with every request that actor makes — driving the full middleware stack, no per-request session juggling:

```php
$ctx->actingAs($user, 'agent', ['current-tenant-id' => $tenant->id]);   // persists for all of this actor's requests
$ctx->as('agent')->withSession(['impersonating' => 42])->postJson(...);  // layered on for one request
```

`withSession()` returns a copy of the actor with the extra session data merged, for one request, leaving the actor's baseline session intact.

### Default headers

Headers work the same way: those given to `actingAs()` (or `actingWithToken()`) go with every request the actor makes, `withHeaders()` layers more on for one request, and a request's own headers win over both:

```php
$ctx->actingAs($user, 'agent', headers: ['X-Tenant' => '7', 'Accept-Version' => '2']);
$ctx->as('agent')->withHeaders(['X-Tenant' => '8'])->getJson('/api/orders');
$ctx->as('agent')->getJson('/api/orders', ['X-Tenant' => '9']);
```

For state that lives *outside* the auth guard and session — and especially in [interleaved journeys](interleaving.md), where whose turn it is changes step to step — see the `aroundStep()` hook.
