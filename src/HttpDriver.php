<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bridges actors to the running test case's HTTP methods. Built from closures
 * by RunsJourneys so the package never has to name the test case's concrete
 * class — anything with Laravel's MakesHttpRequests methods works.
 */
final readonly class HttpDriver
{
    /**
     * @param  Closure(Authenticatable): void  $authenticate
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $json
     * @param  Closure(string, string, array<string, mixed>, array<string, string>): TestResponse<Response>  $form
     * @param  Closure(array<string, mixed>): void|null  $session  Applies session data to the next request; null when the test case exposes no session.
     */
    public function __construct(
        private Closure $authenticate,
        private Closure $json,
        private Closure $form,
        private ?Closure $session = null,
    ) {}

    public function authenticate(Authenticatable $user): void
    {
        ($this->authenticate)($user);
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
