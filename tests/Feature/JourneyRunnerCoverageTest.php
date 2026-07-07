<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Vusys\Runabout\Actor;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * Actor's session handling exercised through real HTTP requests (needs a real
 * Laravel session store, so this is a Feature test rather than a unit test
 * against a fake HttpDriver): the form() request path (used by
 * post()/put()/patch()/delete()/get()) must apply an actor's session data
 * exactly like the json() path already does, and withSession() must merge
 * onto the actor's own session rather than replacing or nesting it.
 */
final class JourneyRunnerCoverageTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->middleware(StartSession::class)->any('/session-echo', fn (Request $request): JsonResponse => new JsonResponse([
            'session' => $request->session()->all(),
        ]));
    }

    public function test_form_requests_apply_the_actors_session_like_json_requests_do(): void
    {
        $agent = User::query()->create(['name' => 'agent']);

        $this->journey(new class($agent) extends Journey
        {
            public function __construct(private readonly User $agent) {}

            public function steps(): array
            {
                return [
                    Step::make('post through the form path')
                        ->act(fn (Context $ctx): Actor => $ctx->actingAs($this->agent, 'agent', ['tenant' => 'alpha']))
                        // post() -> Actor::form(), not json(): this is the only
                        // path that reaches the applySession() call under test.
                        ->assert(fn (Context $ctx) => $ctx->as('agent')->post('/session-echo')
                            ->assertJson(['session' => ['tenant' => 'alpha']])),
                ];
            }
        })->shuffles(2)->run();

        $this->addToAssertionCount(1);
    }

    public function test_with_session_merges_onto_the_actors_own_session_without_losing_or_nesting_it(): void
    {
        $agent = User::query()->create(['name' => 'agent']);

        $this->journey(new class($agent) extends Journey
        {
            public function __construct(private readonly User $agent) {}

            public function steps(): array
            {
                return [
                    Step::make('register a tenanted agent with two session keys')
                        ->act(fn (Context $ctx): Actor => $ctx->actingAs($this->agent, 'agent', ['tenant' => 'alpha', 'locale' => 'en']))
                        ->assert(fn (Context $ctx) => $ctx->as('agent')->withSession(['flag' => 'on'])->getJson('/session-echo')
                            // The override must add to the actor's own session,
                            // not replace it (ArrayItemRemoval) or nest it under
                            // a numeric key (SpreadRemoval) — all three keys
                            // must survive the merge together.
                            ->assertJson(['session' => ['tenant' => 'alpha', 'locale' => 'en', 'flag' => 'on']])),
                ];
            }
        })->shuffles(2)->run();

        $this->addToAssertionCount(1);
    }
}
