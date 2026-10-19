<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;
use Vusys\Runabout\Support\FrameworkState;

/**
 * Bridges actors to the running test case's HTTP methods. Built from closures
 * by RunsJourneys so the package never has to name the test case's concrete
 * class — anything with Laravel's MakesHttpRequests methods works.
 */
final readonly class HttpDriver
{
    /**
     * @param  Closure(?Authenticatable, string|non-empty-list<string>|null): void  $authenticate  Authenticates the user on the named guard (or each of a list of guards), or the default one for null. A null user is a guest.
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $json
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $form
     * @param  Closure(array<string, mixed>): void|null  $session  Applies session data to the next request; null when the test case exposes no session.
     * @param  bool  $freshUsers  Reload a saved Eloquent user before each request, as production resolves it from the session.
     * @param  bool  $isolatedRequests  Clear Laravel Context and scoped container instances before each request, as a production request starts without them.
     * @param  list<Closure(TestResponse<Response>): bool>  $rejectionRules  A response any of these returns true for was rejected by the app.
     */
    public function __construct(
        private Closure $authenticate,
        private Closure $json,
        private Closure $form,
        private ?Closure $session = null,
        private bool $freshUsers = false,
        private bool $isolatedRequests = false,
        private array $rejectionRules = [],
    ) {}

    /** A copy of this driver that reloads a saved Eloquent user before each request. */
    public function withFreshUsers(): self
    {
        return $this->copy(freshUsers: true);
    }

    /**
     * A copy of this driver that also treats a response $rule returns true
     * for as rejected by the app (a validation error, a refusal). A step
     * whose actor requests were all rejected had no effect.
     *
     * @param  Closure(TestResponse<Response>): bool  $rule
     */
    public function withRejectionRule(Closure $rule): self
    {
        return $this->copy(rejectionRules: [...$this->rejectionRules, $rule]);
    }

    /**
     * Whether any rejection rule says the app rejected this response.
     *
     * @param  TestResponse<Response>  $response
     */
    public function rejects(TestResponse $response): bool
    {
        foreach ($this->rejectionRules as $rule) {
            if ($rule($response)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<Closure(TestResponse<Response>): bool>|null  $rejectionRules
     */
    private function copy(?bool $freshUsers = null, ?bool $isolatedRequests = null, ?array $rejectionRules = null): self
    {
        return new self(
            $this->authenticate,
            $this->json,
            $this->form,
            $this->session,
            $freshUsers ?? $this->freshUsers,
            $isolatedRequests ?? $this->isolatedRequests,
            $rejectionRules ?? $this->rejectionRules,
        );
    }

    /**
     * A copy of this driver that makes each request behave like a fresh
     * production request: Laravel Context and scoped container instances are
     * cleared first, and the user is reloaded (as withFreshUsers()).
     */
    public function withIsolatedRequests(): self
    {
        return $this->copy(freshUsers: true, isolatedRequests: true);
    }

    /**
     * Authenticate the next request; every actor request calls this first.
     *
     * @param  string|non-empty-list<string>|null  $guard
     */
    public function authenticate(Authenticatable $user, string|array|null $guard = null): void
    {
        if ($this->isolatedRequests) {
            FrameworkState::flushRequestState();
        }

        // A deleted user reloads as null, and the request goes out as a guest.
        ($this->authenticate)($this->freshUsers && $user instanceof Model && $user->exists ? $user->fresh() : $user, $guard);
    }

    /**
     * Send the next request as a guest: log out the default guard, and every
     * guard an actor has used. A token actor calls this so a session login
     * left by another actor can't shadow its bearer token.
     */
    public function authenticateGuest(): void
    {
        if ($this->isolatedRequests) {
            FrameworkState::flushRequestState();
        }

        ($this->authenticate)(null, null);
    }

    /**
     * Apply an actor's session data to the next request. A no-op for empty
     * session data; a clear error if an actor carries session but the driver
     * was built without a session hook.
     *
     * @param  array<string, mixed>  $session
     */
    public function applySession(array $session): void
    {
        if ($session === []) {
            return;
        }

        if (! $this->session instanceof Closure) {
            throw new \RuntimeException('This HTTP driver has no session hook, so actor session data cannot be applied. Build the driver through RunsJourneys::journeyHttpDriver().');
        }

        ($this->session)($session);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function json(string $method, string $uri, array $data, array $headers): TestResponse
    {
        return ($this->json)($method, $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function form(string $method, string $uri, array $data, array $headers): TestResponse
    {
        return ($this->form)($method, $uri, $data, $headers);
    }
}
