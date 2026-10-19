<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use ArrayObject;
use Illuminate\Foundation\Application;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\Facades\Context as LaravelContext;
use PHPUnit\Framework\Assert;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * Test requests share one application, so request-scoped state (Laravel
 * Context, scoped bindings) outlives the request that set it. With
 * isolateRequests() each actor request starts without it, as in production.
 */
final class IsolateRequestsTest extends TestCase
{
    use RunsJourneys;

    /** @param Application $app */
    #[\Override]
    protected function defineEnvironment($app): void
    {
        $app->scoped('request.counter', fn (): ArrayObject => new ArrayObject);
    }

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->post('/mark', function (): JsonResponse {
            LaravelContext::add('marked', true);

            return new JsonResponse;
        });
        $router->get('/check', fn (): JsonResponse => new JsonResponse(['marked' => LaravelContext::has('marked')]));
        $router->get('/count', function (): JsonResponse {
            $counter = resolve('request.counter');
            assert($counter instanceof ArrayObject);
            $counter->append(true);

            return new JsonResponse(['count' => $counter->count()]);
        });
        $router->get('/me', fn (Request $request): JsonResponse => new JsonResponse(['name' => $request->user()?->getAttribute('name')]));
    }

    public function test_context_set_by_one_request_is_not_seen_by_the_next(): void
    {
        $this->assertSame([false], $this->observe(fn (Context $ctx): mixed => [
            $ctx->as('ana')->postJson('/mark'),
            $ctx->as('ana')->getJson('/check')->json('marked'),
        ][1], isolated: true));
    }

    public function test_by_default_context_outlives_the_request_that_set_it(): void
    {
        $this->assertSame([true], $this->observe(fn (Context $ctx): mixed => [
            $ctx->as('ana')->postJson('/mark'),
            $ctx->as('ana')->getJson('/check')->json('marked'),
        ][1], isolated: false));
    }

    public function test_context_set_in_a_step_is_cleared_before_the_next_request(): void
    {
        $this->assertSame([false], $this->observe(function (Context $ctx): mixed {
            LaravelContext::add('marked', true);

            return $ctx->as('ana')->getJson('/check')->json('marked');
        }, isolated: true));
    }

    public function test_scoped_instances_start_fresh_for_each_request(): void
    {
        $this->assertSame([[1, 1]], $this->observe(fn (Context $ctx): array => [
            $ctx->as('ana')->getJson('/count')->json('count'),
            $ctx->as('ana')->getJson('/count')->json('count'),
        ], isolated: true));

        $this->assertSame([[1, 2]], $this->observe(fn (Context $ctx): array => [
            $ctx->as('ana')->getJson('/count')->json('count'),
            $ctx->as('ana')->getJson('/count')->json('count'),
        ], isolated: false));
    }

    public function test_isolated_requests_reload_the_actors_user(): void
    {
        $this->assertSame(['renamed'], $this->observe(function (Context $ctx): mixed {
            User::query()->whereKey($ctx->as('ana')->user()->getAuthIdentifier())->update(['name' => 'renamed']);

            return $ctx->as('ana')->getJson('/me')->json('name');
        }, isolated: true));
    }

    public function test_fresh_actors_keeps_requests_isolated_in_either_order(): void
    {
        $check = fn (Context $ctx): mixed => [
            $ctx->as('ana')->postJson('/mark'),
            $ctx->as('ana')->getJson('/check')->json('marked'),
        ][1];

        $this->assertSame([false], $this->observe($check, configure: fn (PendingJourney $journey): PendingJourney => $journey->isolateRequests()->freshActors()), 'isolate, then fresh');
        $this->assertSame([false], $this->observe($check, configure: fn (PendingJourney $journey): PendingJourney => $journey->freshActors()->isolateRequests()), 'fresh, then isolate');
    }

    public function test_isolate_requests_without_an_http_driver_is_a_no_op(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('only')->act(fn (Context $ctx): mixed => $ctx->remember('ran', true))->assert(fn (Context $ctx) => Assert::assertTrue($ctx->get('ran')))];
            }
        };

        (new PendingJourney($journey, fn (\Closure $trail) => $trail()))->isolateRequests()->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    /**
     * Run a one-step journey as actor "ana" and collect what $observe returns on each trail.
     *
     * @param  \Closure(Context): mixed  $observe
     * @param  (\Closure(PendingJourney): PendingJourney)|null  $configure
     * @return list<mixed>
     */
    private function observe(\Closure $observe, bool $isolated = false, ?\Closure $configure = null): array
    {
        $ana = User::query()->firstOrCreate(['name' => 'ana']);
        /** @var ArrayObject<int, mixed> $seen */
        $seen = new ArrayObject;

        $journey = new class($ana, $observe, $seen) extends Journey
        {
            /**
             * @param  \Closure(Context): mixed  $observe
             * @param  ArrayObject<int, mixed>  $seen
             */
            public function __construct(private readonly User $ana, private readonly \Closure $observe, private readonly ArrayObject $seen) {}

            public function actors(): array
            {
                return ['ana' => $this->ana];
            }

            public function steps(): array
            {
                return [Step::make('observe')->act(function (Context $ctx): void {
                    $this->seen->append(($this->observe)($ctx));
                })];
            }
        };

        $pending = $this->journey($journey)->shuffles(0);
        $pending = $configure instanceof \Closure ? $configure($pending) : ($isolated ? $pending->isolateRequests() : $pending);
        $pending->run();

        LaravelContext::flush();
        $this->app?->forgetScopedInstances();

        return array_values($seen->getArrayCopy());
    }
}
