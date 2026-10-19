<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Symfony\Component\HttpFoundation\Response;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\DefaultHooksJourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\JourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\Models\User;

/**
 * Server errors from user actions, and responses that break an app-wide
 * rule, fail the step that received them instead of going unnoticed.
 */
final class ResponsePolicyTest extends JourneyTestCase
{
    /** @var (Closure(TestResponse<Response>): void)|null */
    private ?Closure $policy = null;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/status/{status}', fn (int $status): JsonResponse => new JsonResponse(['status' => $status], $status));
    }

    #[\Override]
    protected function journeyResponsePolicy(TestResponse $response): void
    {
        if ($this->policy instanceof Closure) {
            ($this->policy)($response);
        }
    }

    public function test_a_server_error_fails_the_step_that_received_it(): void
    {
        $message = $this->failureOf($this->requesting(500)->failOnServerErrors());

        $this->assertStringContainsString("An actor request was answered with a server error (500).\nLast response (GET /status/500): 500", $message);
    }

    public function test_allowed_server_errors_pass_and_others_still_fail(): void
    {
        $this->requesting(503)->failOnServerErrors(allowing: [503])->run();

        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500)->failOnServerErrors(allowing: [503])));
    }

    public function test_client_errors_and_the_default_are_left_alone(): void
    {
        $this->requesting(422)->failOnServerErrors()->run();
        $this->requesting(499)->failOnServerErrors()->run();
        $this->requesting(500)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_test_case_policy_sees_every_actor_response(): void
    {
        $seen = [];
        $this->policy = function (TestResponse $response) use (&$seen): void {
            $seen[] = $response->getStatusCode();
        };

        $this->requesting(204)->run();

        $this->assertSame([204], $seen);
    }

    public function test_a_test_case_policy_that_throws_fails_the_step(): void
    {
        $this->policy = fn (TestResponse $response) => Assert::assertSame(200, $response->getStatusCode(), 'Every response must be a 200');

        $this->assertStringContainsString('Every response must be a 200', $this->failureOf($this->requesting(201)));
    }

    public function test_a_form_request_is_checked_too(): void
    {
        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500, form: true)->failOnServerErrors()));
    }

    public function test_the_test_case_policy_and_the_server_error_check_both_apply(): void
    {
        $seen = [];
        $this->policy = function (TestResponse $response) use (&$seen): void {
            $seen[] = $response->getStatusCode();
        };

        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500)->failOnServerErrors()));
        $this->assertSame([500], $seen);
    }

    public function test_the_options_are_no_ops_without_an_http_driver(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('only')->act(fn (Context $ctx): mixed => $ctx->remember('ran', true))];
            }
        };

        (new PendingJourney($journey, fn (Closure $trail) => $trail()))
            ->failOnServerErrors()
            ->withResponsePolicy(fn () => Assert::fail('never called'))
            ->shuffles(0)
            ->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_default_test_case_policy_accepts_any_response(): void
    {
        /** @var TestResponse<Response> $response */
        $response = TestResponse::fromBaseResponse(new Response('', 500));

        (new DefaultHooksJourneyTestCase('defaultResponsePolicy'))->defaultResponsePolicy($response);

        $this->addToAssertionCount(1);
    }

    public function test_other_options_keep_the_server_error_check(): void
    {
        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500)->failOnServerErrors()->freshActors()));
        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500)->failOnServerErrors()->isolateRequests()));
        $this->assertStringContainsString('server error (500)', $this->failureOf($this->requesting(500)->isolateRequests()->failOnServerErrors()));
    }

    private function requesting(int $status, bool $form = false): PendingJourney
    {
        $ana = User::query()->firstOrCreate(['name' => 'ana']);

        return $this->journey(new class($ana, $status, $form) extends Journey
        {
            public function __construct(private readonly User $ana, private readonly int $status, private readonly bool $form) {}

            public function actors(): array
            {
                return ['ana' => $this->ana];
            }

            public function steps(): array
            {
                return [Step::make('request')->act(fn (Context $ctx): TestResponse => $this->form
                    ? $ctx->as('ana')->get('/status/'.$this->status)
                    : $ctx->as('ana')->getJson('/status/'.$this->status))];
            }
        })->shuffles(0);
    }

    private function failureOf(PendingJourney $journey): string
    {
        try {
            $journey->run();
        } catch (JourneyFailedException $failure) {
            return $failure->getMessage();
        }

        $this->fail('The journey was expected to fail.');
    }
}
