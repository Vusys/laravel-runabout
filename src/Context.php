<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use DateTimeInterface;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Date;
use Illuminate\Testing\TestResponse;
use Random\Randomizer;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use UnitEnum;
use Vusys\Runabout\Execution\DeferredStack;
use Vusys\Runabout\Randomness\DrawSource;
use Vusys\Runabout\Randomness\StreamDrawSource;
use Vusys\Runabout\Support\Races;

/**
 * The mutable bag threaded through a single trail: remembered values, run
 * history, deferred teardowns, and the seeded source of all randomness.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $runs = [];

    private readonly DeferredStack $deferred;

    /** @var array<string, Actor> */
    private array $actors = [];

    /** @var TestResponse<Response>|null */
    private ?TestResponse $lastResponse = null;

    private bool $checkingInvariants = false;

    /** @var TestResponse<Response>|null The response of the running step's most recent actor request. */
    private ?TestResponse $stepResponse = null;

    /** Whether the running step said it had no effect, with noop(), and why. */
    private bool $noop = false;

    private string $noopReason = '';

    /** Whether the running step made an actor request, and whether the app accepted any. */
    private bool $madeRequest = false;

    private bool $requestAccepted = false;

    private bool $clockUnwindRegistered = false;

    private DrawSource $source;

    public function __construct(
        Randomizer|DrawSource $source,
        private readonly ?HttpDriver $http = null,
        ?DeferredStack $deferred = null,
    ) {
        $this->source = $source instanceof DrawSource ? $source : new StreamDrawSource($source);
        $this->deferred = $deferred ?? new DeferredStack;
    }

    /**
     * @internal Install the draw source for the execution about to run.
     *
     * Under seed schema v2 the runner swaps in a fresh per-execution source
     * before every step (and its invariant checks), so randomInt()/pick()/
     * randomizer() draw values that depend only on which execution it is —
     * never on what ran before it. That position-independence is what lets a
     * shrunk or reordered trail reproduce a failure verbatim. The value
     * shrinker swaps in a ScriptedDrawSource to force specific values.
     */
    public function useSource(DrawSource $source): void
    {
        $this->source = $source;
    }

    public function remember(string $key, mixed $value): mixed
    {
        $this->values[$key] = $value;

        return $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    /**
     * A typed get(): returns the remembered value, guaranteeing its class so
     * call sites (and static analysis) don't have to deal with mixed.
     *
     * @template T of object
     *
     * @param  class-string<T>  $class
     * @return T
     */
    public function instance(string $key, string $class): object
    {
        $value = $this->get($key);

        if (! $value instanceof $class) {
            throw new RuntimeException(sprintf(
                'Context key "%s" holds %s, expected %s.',
                $key,
                get_debug_type($value),
                $class,
            ));
        }

        return $value;
    }

    /** A typed get() for remembered integers (IDs from responses, counters). */
    public function integer(string $key): int
    {
        $value = $this->get($key);

        if (! is_int($value)) {
            throw new RuntimeException(sprintf('Context key "%s" holds %s, expected int.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /** A typed get() for remembered strings. */
    public function string(string $key): string
    {
        $value = $this->get($key);

        if (! is_string($value)) {
            throw new RuntimeException(sprintf('Context key "%s" holds %s, expected string.', $key, get_debug_type($value)));
        }

        return $value;
    }

    /**
     * Append a value to the list remembered under $key, starting one when the
     * key is absent. The step-side companion of list().
     *
     * @return list<mixed> The updated list.
     */
    public function push(string $key, mixed $value): array
    {
        $list = [...$this->list($key), $value];

        $this->values[$key] = $list;

        return $list;
    }

    /**
     * The list remembered under $key — an empty list when the key is absent,
     * a RuntimeException when it holds anything other than a list.
     *
     * @return list<mixed>
     */
    public function list(string $key): array
    {
        if (! $this->has($key)) {
            return [];
        }

        $value = $this->values[$key];

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('Context key "%s" holds %s, expected a list.', $key, get_debug_type($value)));
        }

        return $value;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    /**
     * All randomness inside steps must come from these methods (or the
     * Randomizer itself) so that a seed reproduces the trail exactly. Draws made
     * here are recorded and are candidates for value shrinking.
     */
    public function randomInt(int $min, int $max): int
    {
        return $this->source->int($min, $max);
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $options
     * @return T
     */
    public function pick(array $options): mixed
    {
        return $options[$this->source->int(0, count($options) - 1, fn (int $index): ?string => $this->describePick($options[$index]))];
    }

    /** A few characters naming a picked option for the trail listing, or null when it has no short form. */
    private function describePick(mixed $option): ?string
    {
        return match (true) {
            $option instanceof Model => class_basename($option).(is_scalar($option->getKey()) ? ' #'.$option->getKey() : ''),
            $option instanceof UnitEnum => class_basename($option).'::'.$option->name,
            is_string($option) => json_encode(strlen($option) > 24 ? substr($option, 0, 23).'…' : $option, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?: null,
            is_int($option), is_float($option) => (string) $option,
            is_bool($option) => $option ? 'true' : 'false',
            $option === null => 'null',
            default => null,
        };
    }

    /**
     * The raw randomizer escape hatch. Taking it marks the execution
     * value-opaque: its draws still replay verbatim, but the value shrinker
     * leaves them alone (it cannot see what was drawn through the raw handle).
     */
    public function randomizer(): Randomizer
    {
        return $this->source->randomizer();
    }

    public function timesRan(string $step): int
    {
        return $this->runs[$step] ?? 0;
    }

    /**
     * Whether the step has completed a previous execution. Inside a step's own
     * assertions this is false on the first run and true on repeats, which is
     * what lets a repeatable step change its assertions.
     */
    public function ranBefore(string $step): bool
    {
        return $this->timesRan($step) > 0;
    }

    /**
     * Register a named actor for the rest of the trail. Requests made through
     * the returned Actor are authenticated as this user; any $session data is
     * applied to every request the actor makes (for apps that carry tenancy or
     * other state in the session), and can be extended per request with
     * Actor::withSession(). A $guard logs the actor in on that guard instead
     * of the default one, for apps with several (a storefront and an admin);
     * a list logs it in on each, for a user of both the UI and the API.
     *
     * Headers given here are sent with every request the actor makes, and
     * can be extended per request with Actor::withHeaders().
     *
     * @param  array<string, mixed>  $session
     * @param  string|list<string>|null  $guard
     * @param  array<string, string>  $headers
     */
    public function actingAs(Authenticatable $user, string $name, array $session = [], string|array|null $guard = null, array $headers = []): Actor
    {
        if ($guard === []) {
            throw new RuntimeException(sprintf('Actor "%s" has an empty list of guards. Name at least one, or pass null for the default guard.', $name));
        }

        return $this->actors[$name] = new Actor($name, $user, $this->http(), $this, $session, $guard, $headers);
    }

    /**
     * Register a named actor that authenticates only by sending a real access
     * token (a Sanctum or Passport plain-text token) as a bearer token. Nobody
     * is logged in for its requests, so a session login can't shadow the
     * token, and token abilities, scopes and expiry apply as in production.
     * $user is what Actor::user() returns.
     *
     * @param  array<string, mixed>  $session
     * @param  array<string, string>  $headers
     */
    public function actingWithToken(Authenticatable $user, string $token, string $name, array $session = [], array $headers = []): Actor
    {
        return $this->actors[$name] = new Actor($name, $user, $this->http(), $this, $session, headers: $headers, token: $token);
    }

    private function http(): HttpDriver
    {
        if (! $this->http instanceof HttpDriver) {
            throw new RuntimeException(
                'No HTTP driver is bound to this context. Actors are available when the journey runs through RunsJourneys::journey() on a Laravel test case.',
            );
        }

        return $this->http;
    }

    /** Retrieve a previously registered actor: $ctx->as('manager')->postJson(...). */
    public function as(string $name): Actor
    {
        if (! isset($this->actors[$name])) {
            throw new RuntimeException($this->actors === []
                ? sprintf('No actor named "%s" is registered. Register actors with $ctx->actingAs($user, \'%s\').', $name, $name)
                : sprintf('No actor named "%s" is registered. Known actors: %s.', $name, implode(', ', array_keys($this->actors))));
        }

        return $this->actors[$name];
    }

    /**
     * The response of the most recent actor request in this trail.
     *
     * @return TestResponse<Response>
     */
    public function lastResponse(): TestResponse
    {
        if (! $this->lastResponse instanceof TestResponse) {
            throw new RuntimeException('No actor has made a request yet in this trail.');
        }

        return $this->lastResponse;
    }

    /**
     * @internal
     *
     * @param  TestResponse<Response>  $response
     * @return TestResponse<Response>
     */
    public function rememberResponse(TestResponse $response): TestResponse
    {
        // A request an invariant makes is an observation, not part of the
        // trail, so it must not become the response a step asserts on.
        if (! $this->checkingInvariants) {
            $this->lastResponse = $response;
            $this->stepResponse = $response;
        }

        return $response;
    }

    /** @internal Forget the previous step's response and effect before the next execution runs. */
    public function beginExecution(): void
    {
        $this->stepResponse = null;
        $this->noop = false;
        $this->noopReason = '';
        $this->madeRequest = false;
        $this->requestAccepted = false;
    }

    /**
     * Say that this execution of the step had no effect: its act found
     * nothing to do (no pending item, nothing eligible). The step still
     * counts as run, but the coverage report and requireEffect() count it as
     * ineffective, so a journey can't pass while its interesting steps never
     * did anything. The coverage report lists the reasons given.
     */
    public function noop(string $reason = ''): void
    {
        $this->noop = true;
        $this->noopReason = $reason;
    }

    /** @internal Why the running step said it had no effect, if it gave a reason. */
    public function noopReason(): string
    {
        return $this->noopReason;
    }

    /** @internal Note an actor request the running step made, and whether the app rejected it. */
    public function noteRequest(bool $rejected): void
    {
        $this->madeRequest = true;
        $this->requestAccepted = $this->requestAccepted || ! $rejected;
    }

    /** @internal Whether the running step had an effect: no noop(), and not every actor request it made was rejected. */
    public function tookEffect(): bool
    {
        return ! $this->noop && (! $this->madeRequest || $this->requestAccepted);
    }

    /**
     * @internal The response of the running step's most recent actor request, null when it made none.
     *
     * @return TestResponse<Response>|null
     */
    public function stepResponse(): ?TestResponse
    {
        return $this->stepResponse;
    }

    /**
     * @internal Run invariant checks without letting their requests replace
     * lastResponse().
     *
     * @param  Closure(): void  $checks
     */
    public function checkingInvariants(Closure $checks): void
    {
        $this->checkingInvariants = true;

        try {
            $checks();
        } finally {
            $this->checkingInvariants = false;
        }
    }

    /**
     * Run $request with a check-then-act race injected: at an unlocked read of
     * $table during the request (the first, usually; which one is a seeded
     * draw, so it replays and shrinks), $competitor runs before the app can
     * act on what it read, as a parallel request landing in that window would.
     * A read with a row lock (FOR UPDATE, FOR SHARE) is never interfered with,
     * as a real lock would hold the competitor back. Invariants judge the
     * result. Have the competitor call the app's own code, so it writes what
     * a real request would.
     *
     * Needs a driver that writes lock clauses into its SQL: MySQL, MariaDB or
     * Postgres. On SQLite the request runs without interference, and the run
     * says so once.
     *
     * @template T
     *
     * @param  Closure(): mixed  $competitor
     * @param  Closure(): T  $request
     * @return T The request's result.
     */
    public function racing(string $table, Closure $competitor, Closure $request): mixed
    {
        // Interfere at each unlocked read with probability 3/4, so the first
        // read is raced most often and shrinking (towards 0) moves the race
        // to the earliest read that still fails.
        return Races::race($table, $competitor, $request, fn (): bool => $this->randomInt(0, 3) < 3);
    }

    /**
     * Freeze the clock at a moment. Unwound automatically at the end of the
     * trail (even on failure), so no trail leaks frozen time into the next.
     */
    public function travelTo(DateTimeInterface|string $moment): void
    {
        $this->unwindClockAtTrailEnd();

        Date::setTestNow(Date::parse($moment));
    }

    /** Move the clock relative to now: $ctx->travel('+1 day'). */
    public function travel(string $modifier): void
    {
        $this->unwindClockAtTrailEnd();

        Date::setTestNow(Date::now()->modify($modifier));
    }

    /** Return to the real clock immediately. */
    public function travelBack(): void
    {
        Date::setTestNow();
    }

    private function unwindClockAtTrailEnd(): void
    {
        if ($this->clockUnwindRegistered) {
            return;
        }

        $this->clockUnwindRegistered = true;
        $this->defer(fn () => Date::setTestNow());
    }

    /** Register cleanup to run LIFO at the end of the trail, even on failure. */
    public function defer(Closure $fn): void
    {
        $this->deferred->push($fn);
    }

    /** @internal */
    public function recordRun(string $step): void
    {
        $this->runs[$step] = $this->timesRan($step) + 1;
    }

    /**
     * @internal
     *
     * @return list<Closure> in LIFO order
     */
    public function drainDeferred(): array
    {
        return $this->deferred->drain();
    }
}
