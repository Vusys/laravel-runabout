<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use PHPUnit\Framework\Assert;
use Random\Engine\Mt19937;
use Random\Randomizer;
use RuntimeException;
use Vusys\Runabout\Context;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * An invariant may make requests through an actor ("what does this actor
 * see?"). Those requests are observations, not part of the trail, so they
 * must not replace the response a step asserts on.
 */
final class InvariantRequestsTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->get('/echo', fn (Request $request): JsonResponse => new JsonResponse(['tag' => $request->query('tag')]));
    }

    public function test_a_request_made_by_an_invariant_does_not_replace_the_steps_last_response(): void
    {
        $this->journey(new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('step request')
                        ->act(function (Context $ctx): void {
                            $ctx->actingAs(User::query()->create(['name' => 'ana']), 'ana');
                            $ctx->as('ana')->getJson('/echo?tag=step');
                        }),

                    Step::make('assert without a request')
                        ->after('step request')
                        ->act(fn (): null => null)
                        ->assert(fn (Context $ctx) => $ctx->lastResponse()->assertJson(['tag' => 'step'])),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('ana sees the echo', function (Context $ctx): void {
                        $ctx->as('ana')->getJson('/echo?tag=invariant')->assertOk();
                    }),
                ];
            }
        })->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_a_baseline_invariant_request_does_not_count_as_the_first_response(): void
    {
        $journey = new class extends Journey
        {
            public function actors(): array
            {
                return ['ana' => User::query()->firstOrFail()];
            }

            public function steps(): array
            {
                return [
                    Step::make('read last response')
                        ->act(function (Context $ctx): void {
                            try {
                                $ctx->lastResponse();
                                $ctx->remember('outcome', 'recorded');
                            } catch (RuntimeException) {
                                $ctx->remember('outcome', 'none yet');
                            }
                        })
                        ->assert(fn (Context $ctx) => Assert::assertSame('none yet', $ctx->get('outcome'))),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('ana sees the echo', function (Context $ctx): void {
                        $ctx->as('ana')->getJson('/echo?tag=baseline')->assertOk();
                    })->fromStart(),
                ];
            }
        };

        User::query()->create(['name' => 'ana']);

        $this->journey($journey)->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_requests_after_an_invariant_check_are_recorded_again_even_when_the_check_threw(): void
    {
        $ctx = new Context(new Randomizer(new Mt19937(1)), $this->journeyHttpDriver());
        $ctx->actingAs(User::query()->create(['name' => 'ana']), 'ana');

        try {
            $ctx->checkingInvariants(function () use ($ctx): never {
                $ctx->as('ana')->getJson('/echo?tag=invariant');

                throw new RuntimeException('violated');
            });
        } catch (RuntimeException) {
            // The violation propagates; the flag must not stay set.
        }

        $ctx->as('ana')->getJson('/echo?tag=step');

        $ctx->lastResponse()->assertJson(['tag' => 'step']);
    }
}
