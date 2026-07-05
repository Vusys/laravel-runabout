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
     */
    public function __construct(
        private Closure $authenticate,
        private Closure $json,
        private Closure $form,
    ) {}

    public function authenticate(Authenticatable $user): void
    {
        ($this->authenticate)($user);
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
