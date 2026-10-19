<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;
use Vusys\Runabout\Support\RollBack;

/** Mix into a Laravel/Testbench TestCase to run journeys inside tests. */
trait RunsJourneys
{
    /** @param class-string<Journey>|Journey $journey */
    protected function journey(string|Journey $journey): PendingJourney
    {
        $journey = is_string($journey) ? new $journey : $journey;

        return (new PendingJourney($journey, $this->wrapTrail(...), $this->journeyHttpDriver(), $this->journeyAssertionCounter()))
            ->watchingTransactionsOn([null])
            ->withRejectionRule($this->journeyIsRejected(...));
    }

    /**
     * Merge-shuffle several journey instances into each trail — different
     * tenants, different actors, one world. The go-to mode for isolation
     * bugs no single journey can expose. See docs/interleave-design.md.
     *
     * @param  class-string<Journey>|Journey  ...$journeys
     */
    protected function interleave(string|Journey ...$journeys): PendingJourney
    {
        $instances = array_values(array_map(
            fn (string|Journey $journey): Journey => is_string($journey) ? new $journey : $journey,
            $journeys,
        ));

        return (new PendingJourney($instances, $this->wrapTrail(...), $this->journeyHttpDriver(), $this->journeyAssertionCounter()))
            ->watchingTransactionsOn([null])
            ->withRejectionRule($this->journeyIsRejected(...));
    }

    /**
     * Whether the app rejected the request this response answers (a
     * validation error, a refusal). A step whose actor requests were all
     * rejected had no effect, which the coverage report and requireEffect()
     * count. The default rejects nothing; a typical rule is
     * `$response->isClientError()`, or an API's `status: error` body.
     *
     * @param  TestResponse<Response>  $response
     */
    protected function journeyIsRejected(TestResponse $response): bool
    {
        return false;
    }

    /**
     * Passing invariant checks count as assertions, so a journey that only
     * uses invariants isn't reported as risky for performing none.
     *
     * Override to return null to leave the count alone.
     *
     * @return (Closure(): void)|null
     */
    protected function journeyAssertionCounter(): ?Closure
    {
        return function (): void {
            $this->addToAssertionCount(1);
        };
    }

    /**
     * Logs an actor in before each of its requests: on the default guard
     * (null) with actingAs(), on a named guard with setUser() alone. Override
     * it for guards that need more, such as a token guard whose app reads
     * abilities or a tenant off the current access token:
     *
     *     $guard === 'api' ? Sanctum::actingAs($user, ['orders:write'], 'api') : parent::journeyAuthenticate($user, $guard);
     */
    protected function journeyAuthenticate(Authenticatable $user, ?string $guard): void
    {
        if ($guard === null) {
            $this->actingAs($user);
        } else {
            Auth::guard($guard)->setUser($user);
        }
    }

    /**
     * Adapts this test case's HTTP methods (Laravel's MakesHttpRequests) so
     * actors inside steps can make authenticated requests through it.
     *
     * An actor on a named guard is set on that guard only. actingAs() would
     * also make it the default guard, so code calling auth()->user() would
     * see an admin as the current customer.
     */
    protected function journeyHttpDriver(): HttpDriver
    {
        /** @var array<string, string> $guards */
        $guards = [];
        $default = null;
        $named = false;

        return new HttpDriver(
            authenticate: function (?Authenticatable $user, ?string $guard) use (&$guards, &$default, &$named): void {
                $default ??= Auth::getDefaultDriver();
                $guards[$guard ?? $default] = $guard ?? $default;
                $named = $named || $guard !== null;

                // In production each request starts on the configured default
                // guard and with only its own actor's session. The test app
                // outlives a request, and `auth:admin` middleware switches the
                // default guard, so undo both once a named guard is in play.
                if ($named) {
                    Auth::shouldUse($default);

                    foreach ($guards as $other) {
                        $resolved = Auth::guard($other);

                        if (method_exists($resolved, 'forgetUser')) {
                            $resolved->forgetUser();
                        }
                    }
                }

                if (! $user instanceof Authenticatable) {
                    $resolved = Auth::guard($guard);

                    if (method_exists($resolved, 'forgetUser')) {
                        $resolved->forgetUser();
                    }
                } else {
                    $this->journeyAuthenticate($user, $guard);
                }
            },
            json: fn (string $method, string $uri, array $data, array $headers): TestResponse => $this->json($method, $uri, $data, $headers),
            form: fn (string $method, string $uri, array $data, array $headers): TestResponse => match ($method) {
                'GET' => $this->get($uri, $headers),
                'POST' => $this->post($uri, $data, $headers),
                'PUT' => $this->put($uri, $data, $headers),
                'PATCH' => $this->patch($uri, $data, $headers),
                'DELETE' => $this->delete($uri, $data, $headers),
                default => throw new InvalidArgumentException(sprintf('Unsupported HTTP method "%s".', $method)),
            },
            session: function (array $data): void {
                $this->withSession($data);
            },
        );
    }

    /**
     * Each trail runs against fresh database state: by default a transaction
     * on the default connection, rolled back afterwards. Override for apps
     * that need truncation or multiple connections.
     */
    protected function wrapTrail(Closure $trail): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        RollBack::after($trail, [$connection]);
    }
}
