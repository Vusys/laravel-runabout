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
     * @param  Closure(?Authenticatable, ?string): void  $authenticate  Authenticates the user on the named guard, or the default one for null. A null user is a guest.
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $json
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $form
     * @param  Closure(array<string, mixed>): void|null  $session  Applies session data to the next request; null when the test case exposes no session.
     * @param  bool  $freshUsers  Reload a saved Eloquent user before each request, as production resolves it from the session.
     * @param  bool  $isolatedRequests  Clear Laravel Context and scoped container instances before each request, as a production request starts without them.
     */
    public function __construct(
        private Closure $authenticate,
        private Closure $json,
        private Closure $form,
        private ?Closure $session = null,
        private bool $freshUsers = false,
        private bool $isolatedRequests = false,
    ) {}

    /** A copy of this driver that reloads a saved Eloquent user before each request. */
    public function withFreshUsers(): self
    {
        return new self($this->authenticate, $this->json, $this->form, $this->session, true, $this->isolatedRequests);
    }

    /**
     * A copy of this driver that makes each request behave like a fresh
     * production request: Laravel Context and scoped container instances are
     * cleared first, and the user is reloaded (as withFreshUsers()).
     */
    public function withIsolatedRequests(): self
    {
        return new self($this->authenticate, $this->json, $this->form, $this->session, true, true);
    }

    /** Authenticate the next request; every actor request calls this first. */
    public function authenticate(Authenticatable $user, ?string $guard = null): void
    {
        if ($this->isolatedRequests) {
            FrameworkState::flushRequestState();
        }

        // A deleted user reloads as null, and the request goes out as a guest.
        ($this->authenticate)($this->freshUsers && $user instanceof Model && $user->exists ? $user->fresh() : $user, $guard);
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
