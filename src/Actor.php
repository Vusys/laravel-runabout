<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * A named participant in a journey. Every request made through an actor is
 * authenticated as that actor's user, so steps can freely hop between
 * participants: $ctx->as('manager')->postJson(...).
 */
final class Actor
{
    public function __construct(
        private readonly string $name,
        private readonly Authenticatable $user,
        private readonly HttpDriver $http,
        private readonly Context $context,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    public function user(): Authenticatable
    {
        return $this->user;
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function getJson(string $uri, array $headers = []): TestResponse
    {
        return $this->json('GET', $uri, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function postJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('POST', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function putJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PUT', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function patchJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('PATCH', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function deleteJson(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->json('DELETE', $uri, $data, $headers);
    }

    /**
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function get(string $uri, array $headers = []): TestResponse
    {
        return $this->form('GET', $uri, [], $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function post(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->form('POST', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function put(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->form('PUT', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function patch(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->form('PATCH', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    public function delete(string $uri, array $data = [], array $headers = []): TestResponse
    {
        return $this->form('DELETE', $uri, $data, $headers);
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function json(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $this->http->authenticate($this->user);

        return $this->context->rememberResponse($this->http->json($method, $uri, $data, $headers));
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function form(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $this->http->authenticate($this->user);

        return $this->context->rememberResponse($this->http->form($method, $uri, $data, $headers));
    }
}
