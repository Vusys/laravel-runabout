<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Http\JsonResponse;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Auth;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\JourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\Models\User;

/** journeyAuthenticate() logs each actor in, so a test case can attach what a token guard needs. */
final class AuthenticateHookTest extends JourneyTestCase
{
    /** @var list<array{string, string|null}> */
    private array $authenticated = [];

    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app['config']->set('auth.guards.api', ['driver' => 'session', 'provider' => 'users']);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/whoami', fn (): JsonResponse => new JsonResponse([
            'default' => Auth::getDefaultDriver(),
            'web' => Auth::guard('web')->id(),
            'api' => Auth::guard('api')->id(),
            'token' => Auth::guard('api')->user()?->getAttribute('token'),
        ]));
    }

    #[\Override]
    protected function journeyAuthenticate(Authenticatable $user, ?string $guard): void
    {
        $this->assertInstanceOf(User::class, $user);
        $this->authenticated[] = [$user->name, $guard];

        if ($guard === 'api') {
            $user->setAttribute('token', 'orders:write');
            Auth::guard('api')->setUser($user);
            // As Sanctum::actingAs() and Passport::actingAs() do.
            Auth::shouldUse('api');

            return;
        }

        parent::journeyAuthenticate($user, $guard);
    }

    public function test_every_actor_request_goes_through_the_hook(): void
    {
        $shopper = User::query()->create(['name' => 'shopper']);
        $integration = User::query()->create(['name' => 'integration']);

        $this->journey(new class($shopper, $integration) extends Journey
        {
            public function __construct(private readonly User $shopper, private readonly User $integration) {}

            #[\Override]
            public function actors(): array
            {
                return ['shopper' => $this->shopper, 'integration' => [$this->integration, 'api']];
            }

            public function steps(): array
            {
                return [
                    Step::make('shop')->act(fn (Context $ctx) => $ctx->as('shopper')->getJson('/whoami')
                        ->assertExactJson(['default' => 'web', 'web' => $this->shopper->id, 'api' => null, 'token' => null])),
                    Step::make('integrate')->act(fn (Context $ctx) => $ctx->as('integration')->getJson('/whoami')
                        ->assertExactJson(['default' => 'web', 'web' => null, 'api' => $this->integration->id, 'token' => 'orders:write'])),
                ];
            }
        })->shuffles(0)->run();

        $this->assertSame([['shopper', null], ['integration', 'api']], $this->authenticated);
    }
}
