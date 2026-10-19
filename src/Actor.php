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
final readonly class Actor
{
    /**
     * @param  array<string, mixed>  $session  Applied to every request this actor makes.
     * @param  string|non-empty-list<string>|null  $guard  The auth guard this actor logs in on, or several; null for the default guard.
     * @param  array<string, string>  $headers  Sent with every request this actor makes; a request's own headers win.
     * @param  string|null  $token  A plain-text access token sent as a bearer token, in place of logging the user in.
     */
    public function __construct(
        private string $name,
        private Authenticatable $user,
        private HttpDriver $http,
        private Context $context,
        private array $session = [],
        private string|array|null $guard = null,
        private array $headers = [],
        private ?string $token = null,
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
     * A copy of this actor with additional session data merged in — for a
     * request (or run of requests) that needs session state on top of the
     * actor's own: $ctx->as('agent')->withSession(['tenant' => 5])->postJson(...).
     *
     * @param  array<string, mixed>  $session
     */
    public function withSession(array $session): self
    {
        return new self($this->name, $this->user, $this->http, $this->context, [...$this->session, ...$session], $this->guard, $this->headers, $this->token);
    }

    /**
     * A copy of this actor that also sends these headers, on top of its own:
     * $ctx->as('agent')->withHeaders(['X-Tenant' => '5'])->getJson(...).
     * To send them on every request, pass headers to actingAs().
     *
     * @param  array<string, string>  $headers
     */
    public function withHeaders(array $headers): self
    {
        return new self($this->name, $this->user, $this->http, $this->context, $this->session, $this->guard, [...$this->headers, ...$headers], $this->token);
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

    /** Log in (or, for a token actor, make sure nobody is logged in) and apply the session for the next request. */
    private function prepare(): void
    {
        if ($this->token === null) {
            $this->http->authenticate($this->user, $this->guard);
        } else {
            $this->http->authenticateGuest();
        }

        $this->http->applySession($this->session);
    }

    /**
     * @param  array<string, string>  $headers
     * @return array<string, string>
     */
    private function headersWith(array $headers): array
    {
        return [
            ...$this->token === null ? [] : ['Authorization' => 'Bearer '.$this->token],
            ...$this->headers,
            ...$headers,
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function json(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $this->prepare();

        $response = $this->context->rememberResponse($this->http->json($method, $uri, $data, $this->headersWith($headers)));
        $this->context->noteRequest($this->http->rejects($response));

        return $response;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, string>  $headers
     * @return TestResponse<Response>
     */
    private function form(string $method, string $uri, array $data, array $headers): TestResponse
    {
        $this->prepare();

        $response = $this->context->rememberResponse($this->http->form($method, $uri, $data, $this->headersWith($headers)));
        $this->context->noteRequest($this->http->rejects($response));

        return $response;
    }
}
