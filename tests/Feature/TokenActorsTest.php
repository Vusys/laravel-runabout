<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context as ContextFacade;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * actingWithToken() authenticates by bearer token alone, with nobody logged
 * in to shadow it; headers given to an actor go with each of its requests.
 */
final class TokenActorsTest extends TestCase
{
    use RunsJourneys;

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('k', 32)));
        $app['config']->set('auth.guards.admin', ['driver' => 'session', 'provider' => 'users']);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $whoami = fn (Request $request): JsonResponse => new JsonResponse([
            'web' => Auth::guard('web')->id(),
            'admin' => Auth::guard('admin')->id(),
            'token' => $request->bearerToken(),
            'tenant' => $request->header('X-Tenant'),
            'version' => $request->header('X-Version'),
            'context' => ContextFacade::get('marker'),
        ]);

        $router->get('/whoami', $whoami);
        $router->middleware('web')->get('/page', $whoami);
    }

    public function test_a_token_actor_sends_its_token_with_nobody_logged_in(): void
    {
        $shopper = User::query()->create(['name' => 'shopper']);
        $admin = User::query()->create(['name' => 'admin']);
        $integration = User::query()->create(['name' => 'integration']);

        $this->journey(new class($shopper, $admin, $integration) extends Journey
        {
            public function __construct(private readonly User $shopper, private readonly User $admin, private readonly User $integration) {}

            #[\Override]
            public function actors(): array
            {
                return ['shopper' => $this->shopper, 'admin' => [$this->admin, 'admin']];
            }

            public function steps(): array
            {
                return [
                    Step::make('shop')->repeatable(max: 2)->act(fn (Context $ctx) => $ctx->as('shopper')->getJson('/whoami')
                        ->assertJson(['web' => $this->shopper->id, 'token' => null])),
                    Step::make('administer')->repeatable(max: 2)->act(fn (Context $ctx) => $ctx->as('admin')->getJson('/whoami')
                        ->assertJson(['admin' => $this->admin->id, 'token' => null])),
                    Step::make('integrate')->repeatable(max: 2)->act(function (Context $ctx): void {
                        $actor = $ctx->has('integration') ? $ctx->as('integration') : $ctx->actingWithToken($this->integration, 'plain-token', 'integration');
                        $ctx->remember('integration', true);

                        Assert::assertSame($this->integration, $actor->user());
                        $actor->getJson('/whoami')->assertExactJson(['web' => null, 'admin' => null, 'token' => 'plain-token', 'tenant' => null, 'version' => null, 'context' => null]);
                        $actor->get('/page')->assertJson(['web' => null, 'admin' => null, 'token' => 'plain-token']);
                    }),
                ];
            }
        })->shuffles(6)->run();

        $this->addToAssertionCount(1);
    }

    public function test_an_actors_headers_go_with_every_request_and_a_requests_own_win(): void
    {
        $user = User::query()->create(['name' => 'agent']);

        $this->journey(new class($user) extends Journey
        {
            public function __construct(private readonly User $user) {}

            public function steps(): array
            {
                return [Step::make('work')->act(function (Context $ctx): void {
                    $ctx->actingAs($this->user, 'agent', headers: ['X-Tenant' => '7']);

                    $ctx->as('agent')->getJson('/whoami')->assertJson(['web' => $this->user->id, 'tenant' => '7', 'version' => null]);
                    $ctx->as('agent')->withHeaders(['X-Version' => '2'])->getJson('/whoami')->assertJson(['tenant' => '7', 'version' => '2']);
                    $ctx->as('agent')->withHeaders(['X-Tenant' => '8'])->withSession(['x' => 1])->get('/page')->assertJson(['tenant' => '8']);
                    $ctx->as('agent')->getJson('/whoami', ['X-Tenant' => '9'])->assertJson(['tenant' => '9']);

                    $ctx->actingWithToken($this->user, 'secret', 'api', headers: ['X-Tenant' => '3'])
                        ->withHeaders(['X-Version' => '4'])
                        ->getJson('/whoami')
                        ->assertJson(['web' => null, 'token' => 'secret', 'tenant' => '3', 'version' => '4']);
                })];
            }
        })->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_a_token_actors_requests_are_isolated_too(): void
    {
        $user = User::query()->create(['name' => 'integration']);

        $this->journey(new class($user) extends Journey
        {
            public function __construct(private readonly User $user) {}

            public function steps(): array
            {
                return [Step::make('integrate')->act(function (Context $ctx): void {
                    ContextFacade::add('marker', 'left over');

                    $ctx->actingWithToken($this->user, 'plain-token', 'integration')->getJson('/whoami')->assertJson(['context' => null]);
                })];
            }
        })->isolateRequests()->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }
}
