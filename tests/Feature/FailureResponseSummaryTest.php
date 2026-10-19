<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * A failed step assertion in an HTTP journey usually means the request was
 * rejected for an unexpected reason, so the failure names the response the
 * step last received.
 */
final class FailureResponseSummaryTest extends TestCase
{
    use RunsJourneys;

    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        // The web middleware group encrypts cookies, which needs a key.
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->post('/items', fn (): JsonResponse => new JsonResponse(['message' => 'The name field is required.'], 422));
        $router->get('/long', fn (): JsonResponse => new JsonResponse(['text' => str_repeat('x', 400)]));
        $router->get('/ok', fn (): JsonResponse => new JsonResponse(['ok' => true]));
        $router->get('/text', fn (): Response => new Response("  plain text, padded \n", 500));
        $router->get('/pretty', fn (): Response => new Response("{\n    \"ok\": false\n}", 400, ['Content-Type' => 'application/json']));
        $router->get('/accents', fn (): Response => new Response(str_repeat('é', 299), 200));
        $router->get('/exact', fn (): Response => new Response(str_repeat('y', 300), 200));
        $router->get('/accents-long', fn (): Response => new Response(str_repeat('é', 400), 200));
        $router->get('/slashes', fn (): JsonResponse => new JsonResponse(['url' => 'https://example.com/a/b', 'name' => 'Zoë'], 409));
        $router->middleware('web')->post('/form', function (Request $request): void {
            $request->validate(['name' => ['required']]);
        });
    }

    public function test_a_failing_step_names_its_rejected_json_response(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->postJson('/items'), fn (Context $ctx) => $ctx->lastResponse()->assertCreated());

        // The summary follows the failure reason on a line of its own, and ends
        // a canonical failure's message (which has no replay line).
        $this->assertStringStartsWith('Journey ', $message);
        $this->assertStringEndsWith("Failed asserting that 422 is identical to 201.\nLast response (POST /items): 422\n  body: {\"message\":\"The name field is required.\"}", $message);
    }

    public function test_a_redirect_shows_its_target_and_the_flashed_validation_errors(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->post('/form'), fn () => Assert::fail('rejected'));

        $this->assertMatchesRegularExpression('/Last response \(POST \/form\): 302 → \S+\n  session errors: \{"name":\["The name field is required\."\]\}/', $message);
    }

    public function test_a_long_body_is_truncated(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->getJson('/long'), fn () => Assert::fail('rejected'));

        $this->assertStringContainsString('Last response (GET /long): 200', $message);
        $this->assertStringContainsString('{"text":"'.str_repeat('x', 291).'…', $message);
    }

    public function test_a_plain_text_body_is_trimmed_and_shown_as_it_is(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->get('/text'), fn () => Assert::fail('rejected'));

        $this->assertStringEndsWith("Last response (GET /text): 500\n  body: plain text, padded", $message);
    }

    public function test_a_pretty_printed_json_body_is_compacted(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->getJson('/pretty'), fn () => Assert::fail('rejected'));

        $this->assertStringEndsWith("Last response (GET /pretty): 400\n  body: {\"ok\":false}", $message);
    }

    public function test_the_body_limit_counts_characters_not_bytes(): void
    {
        $accents = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->get('/accents'), fn () => Assert::fail('rejected'));
        $exact = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->get('/exact'), fn () => Assert::fail('rejected'));

        // 299 two-byte characters, and exactly the 300-character limit: neither is cut.
        $this->assertStringEndsWith('  body: '.str_repeat('é', 299), $accents);
        $this->assertStringEndsWith('  body: '.str_repeat('y', 300), $exact);
        $this->assertStringEndsWith('  body: '.str_repeat('é', 300).'…', $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->get('/accents-long'), fn () => Assert::fail('rejected')));
    }

    public function test_json_keeps_slashes_and_unicode_readable(): void
    {
        $message = $this->failureOf(fn (Context $ctx): TestResponse => $ctx->as('ana')->getJson('/slashes'), fn () => Assert::fail('rejected'));

        $this->assertStringEndsWith('  body: {"url":"https://example.com/a/b","name":"Zoë"}', $message);
    }

    public function test_a_step_that_made_no_request_shows_no_response_even_after_an_earlier_one(): void
    {
        $journey = $this->journeyWith(
            [Step::make('fetch')->act(fn (Context $ctx): TestResponse => $ctx->as('ana')->getJson('/ok')),
                Step::make('fail without a request')->after('fetch')->act(fn () => Assert::fail('no request'))],
        );

        $this->assertStringNotContainsString('Last response', $this->messageOf($journey));
    }

    public function test_an_invariant_failure_shows_no_step_response(): void
    {
        $journey = $this->journeyWith(
            [Step::make('fetch')->act(fn (Context $ctx): TestResponse => $ctx->as('ana')->getJson('/ok'))],
            [Invariant::make('never holds', fn () => Assert::fail('violated'))],
        );

        $this->assertStringNotContainsString('Last response', $this->messageOf($journey));
    }

    /**
     * @param  Closure(Context): mixed  $act
     * @param  Closure(Context): mixed  $assert
     */
    private function failureOf(Closure $act, Closure $assert): string
    {
        return $this->messageOf($this->journeyWith([Step::make('request')->act($act)->assert($assert)]));
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    private function journeyWith(array $steps, array $invariants = []): Journey
    {
        User::query()->create(['name' => 'ana']);

        return new class($steps, $invariants) extends Journey
        {
            /**
             * @param  list<Step>  $steps
             * @param  list<Invariant>  $invariants
             */
            public function __construct(private readonly array $steps, private readonly array $invariants) {}

            public function actors(): array
            {
                return ['ana' => User::query()->firstOrFail()];
            }

            public function steps(): array
            {
                return $this->steps;
            }

            public function invariants(): array
            {
                return $this->invariants;
            }
        };
    }

    private function messageOf(Journey $journey): string
    {
        try {
            $this->journey($journey)->shuffles(0)->run();
        } catch (JourneyFailedException $failure) {
            return $failure->getMessage();
        }

        $this->fail('The journey was expected to fail.');
    }
}
