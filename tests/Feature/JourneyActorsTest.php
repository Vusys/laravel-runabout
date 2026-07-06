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
 * Journey::actors() registers named actors on every trail's context without a
 * setup step, and an actor's session data rides along with each request it
 * makes (Actor::withSession() and the actingAs() session argument).
 */
final class JourneyActorsTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        // StartSession makes an actor's session data (set via withSession)
        // readable on the request; without any it is simply empty.
        $router->middleware(StartSession::class)->get('/echo', fn (Request $request): JsonResponse => new JsonResponse([
            'user' => $request->user()?->getAuthIdentifier(),
            'tenant' => $request->session()->get('tenant'),
        ]));
    }

    public function test_declared_actors_are_registered_without_a_setup_step(): void
    {
        $manager = User::query()->create(['name' => 'manager']);
        $agent = User::query()->create(['name' => 'agent']);

        $this->journey(new class($manager, $agent) extends Journey
        {
            public function __construct(private readonly User $manager, private readonly User $agent) {}

            /** @return array<string, User> */
            #[\Override]
            public function actors(): array
            {
                return ['manager' => $this->manager, 'agent' => $this->agent];
            }

            public function steps(): array
            {
                return [
                    // No "register actors" step: actors() already did it.
                    Step::make('manager and agent both act')
                        ->repeatable(max: 3)
                        ->act(function (Context $ctx): void {
                            $ctx->as('manager')->getJson('/echo');
                            $ctx->as('agent')->getJson('/echo');
                        })
                        ->assert(function (Context $ctx): void {
                            // Each request authenticated as its own actor.
                            $ctx->as('manager')->getJson('/echo')
                                ->assertJson(['user' => $this->manager->id]);
                            $ctx->as('agent')->getJson('/echo')
                                ->assertJson(['user' => $this->agent->id]);
                        }),
                ];
            }
        })->shuffles(3)->run();

        $this->addToAssertionCount(1);
    }

    public function test_actor_session_data_rides_along_with_requests(): void
    {
        $agent = User::query()->create(['name' => 'agent']);

        $this->journey(new class($agent) extends Journey
        {
            public function __construct(private readonly User $agent) {}

            public function steps(): array
            {
                return [
                    // A persistent per-actor session (set when registering the
                    // actor) and a per-request override both reach the request.
                    Step::make('register the tenanted agent')
                        ->act(fn (Context $ctx): Actor => $ctx->actingAs($this->agent, 'agent', ['tenant' => 7]))
                        ->assert(fn (Context $ctx) => $ctx->as('agent')->getJson('/echo')
                            ->assertJson(['user' => $this->agent->id, 'tenant' => 7])),

                    Step::make('override the tenant for one request')
                        ->after('register the tenanted agent')
                        ->act(function (Context $ctx): void {
                            $ctx->as('agent')->withSession(['tenant' => 99])->getJson('/echo')
                                ->assertJson(['tenant' => 99]);
                        })
                        // The override does not stick: the actor's own session stands.
                        ->assert(fn (Context $ctx) => $ctx->as('agent')->getJson('/echo')
                            ->assertJson(['tenant' => 7])),
                ];
            }
        })->shuffles(2)->run();

        $this->addToAssertionCount(1);
    }
}
