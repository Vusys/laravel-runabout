<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * Every Actor verb — JSON and form, all five methods — against an echo
 * route, proving each request authenticates as its own actor's user even
 * when actors alternate within a trail.
 */
final class ActorTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->any('/echo', fn (Request $request): JsonResponse => new JsonResponse([
            'method' => $request->method(),
            'user' => $request->user()?->getAuthIdentifier(),
            'payload' => $request->input('payload'),
        ]));
    }

    public function test_every_actor_verb_authenticates_and_echoes(): void
    {
        $this->journey($this->echoJourney())->shuffles(3)->run();

        $this->addToAssertionCount(1);
    }

    private function echoJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('register actors')
                        ->act(function (Context $ctx): void {
                            foreach (['ana', 'ben'] as $name) {
                                $user = User::query()->create(['name' => $name]);
                                $ctx->actingAs($user, $name);
                                $ctx->remember($name.' id', $user->id);
                            }
                        })
                        ->assert(function (Context $ctx): void {
                            Assert::assertSame('ana', $ctx->as('ana')->name());
                            Assert::assertSame($ctx->integer('ana id'), $ctx->as('ana')->user()->getAuthIdentifier());
                        }),

                    Step::make('json verbs')
                        ->after('register actors')
                        ->act(function (Context $ctx): void {
                            $ana = $ctx->integer('ana id');

                            $ctx->as('ana')->getJson('/echo')
                                ->assertOk()->assertJson(['method' => 'GET', 'user' => $ana]);
                            $ctx->as('ana')->postJson('/echo', ['payload' => 'made'])
                                ->assertOk()->assertJson(['method' => 'POST', 'user' => $ana, 'payload' => 'made']);
                            $ctx->as('ana')->putJson('/echo', ['payload' => 'replaced'])
                                ->assertOk()->assertJson(['method' => 'PUT', 'user' => $ana, 'payload' => 'replaced']);
                            $ctx->as('ana')->patchJson('/echo', ['payload' => 'tweaked'])
                                ->assertOk()->assertJson(['method' => 'PATCH', 'user' => $ana, 'payload' => 'tweaked']);
                            $ctx->as('ana')->deleteJson('/echo', ['payload' => 'gone'])
                                ->assertOk()->assertJson(['method' => 'DELETE', 'user' => $ana, 'payload' => 'gone']);
                        })
                        ->assert(fn (Context $ctx) => $ctx->lastResponse()->assertJson(['method' => 'DELETE'])),

                    Step::make('form verbs')
                        ->after('register actors')
                        ->act(function (Context $ctx): void {
                            $ana = $ctx->integer('ana id');
                            $ben = $ctx->integer('ben id');

                            // Alternate actors between requests: each call must
                            // re-authenticate, never inherit the previous user.
                            $ctx->as('ben')->get('/echo')
                                ->assertOk()->assertJson(['method' => 'GET', 'user' => $ben]);
                            $ctx->as('ana')->post('/echo', ['payload' => 'made'])
                                ->assertOk()->assertJson(['method' => 'POST', 'user' => $ana, 'payload' => 'made']);
                            $ctx->as('ben')->put('/echo', ['payload' => 'replaced'])
                                ->assertOk()->assertJson(['method' => 'PUT', 'user' => $ben, 'payload' => 'replaced']);
                            $ctx->as('ana')->patch('/echo', ['payload' => 'tweaked'])
                                ->assertOk()->assertJson(['method' => 'PATCH', 'user' => $ana, 'payload' => 'tweaked']);
                            $ctx->as('ben')->delete('/echo', ['payload' => 'gone'])
                                ->assertOk()->assertJson(['method' => 'DELETE', 'user' => $ben, 'payload' => 'gone']);
                        }),
                ];
            }

            public function invariants(): array
            {
                return [];
            }
        };
    }
}
