<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * An actor can log in on a named guard. It is set on that guard only, so the
 * default guard stays the default, and each request arrives logged in on its
 * own actor's guard and no other.
 */
final class ActorGuardsTest extends TestCase
{
    use RunsJourneys;

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/whoami', fn (): JsonResponse => new JsonResponse([
            'default' => Auth::getDefaultDriver(),
            'web' => Auth::guard('web')->id(),
            'admin' => Auth::guard('admin')->id(),
        ]));

        $router->middleware('auth:admin')->get('/admin', fn (): JsonResponse => new JsonResponse(['ok' => true]));
    }

    public function test_each_request_is_logged_in_on_its_own_actors_guard_only(): void
    {
        $shopper = User::query()->create(['name' => 'shopper']);
        $admin = User::query()->create(['name' => 'admin']);

        $this->journey(new class($shopper, $admin) extends Journey
        {
            public function __construct(private readonly User $shopper, private readonly User $admin) {}

            #[\Override]
            public function actors(): array
            {
                return ['shopper' => $this->shopper, 'admin' => [$this->admin, 'admin']];
            }

            public function steps(): array
            {
                return [
                    Step::make('shop')->repeatable(max: 3)->act(fn (Context $ctx) => $ctx->as('shopper')->getJson('/whoami')
                        ->assertExactJson(['default' => 'web', 'web' => $this->shopper->id, 'admin' => null])),
                    Step::make('administer')->repeatable(max: 3)->act(function (Context $ctx): void {
                        $ctx->as('admin')->getJson('/whoami')
                            ->assertExactJson(['default' => 'web', 'web' => null, 'admin' => $this->admin->id]);
                        $ctx->as('admin')->getJson('/admin')->assertOk();
                    }),
                    Step::make('register a mid-trail admin')->act(function (Context $ctx): void {
                        $ctx->actingAs($this->admin, 'late admin', guard: 'admin')->withSession(['x' => 1])->getJson('/whoami')
                            ->assertJson(['web' => null, 'admin' => $this->admin->id]);
                    }),
                ];
            }
        })->shuffles(4)->run();

        $this->addToAssertionCount(1);
    }

    public function test_a_named_guard_actor_alone_still_starts_each_request_on_the_default_guard(): void
    {
        $admin = User::query()->create(['name' => 'admin']);

        $this->journey(new class($admin) extends Journey
        {
            public function __construct(private readonly User $admin) {}

            #[\Override]
            public function actors(): array
            {
                return ['admin' => [$this->admin, 'admin']];
            }

            public function steps(): array
            {
                return [
                    Step::make('administer')->act(function (Context $ctx): void {
                        // auth:admin switches the default guard for the rest of the test app's life.
                        $ctx->as('admin')->getJson('/admin')->assertOk();
                        $ctx->as('admin')->getJson('/whoami')->assertJson(['default' => 'web']);
                    }),
                ];
            }
        })->run();

        $this->addToAssertionCount(1);
    }
}
