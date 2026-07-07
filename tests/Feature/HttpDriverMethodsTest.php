<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use InvalidArgumentException;
use ReflectionClass;
use Vusys\Runabout\Context;
use Vusys\Runabout\Journey;
use Vusys\Runabout\RunsJourneys;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Tests\TestCase;

/**
 * RunsJourneys's own plumbing: every `form()` verb the HTTP driver maps to a
 * TestCase method, the unsupported-method guard, interleave()'s
 * string-or-instance handling, and the protected visibility subclasses rely
 * on to override journey(), interleave(), journeyHttpDriver() and
 * wrapTrail().
 */
final class HttpDriverMethodsTest extends TestCase
{
    use RunsJourneys;

    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->any('/verb-echo', fn (Request $request): JsonResponse => new JsonResponse([
            'method' => $request->method(),
        ]));
    }

    public function test_every_form_verb_hits_the_route_matching_its_http_method(): void
    {
        $this->journey($this->verbEchoJourney())->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_http_driver_rejects_an_unsupported_form_method(): void
    {
        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('Unsupported HTTP method "OPTIONS".');

        $this->journeyHttpDriver()->form('OPTIONS', '/verb-echo', [], []);
    }

    public function test_interleave_instantiates_class_string_journeys_and_leaves_built_instances_alone(): void
    {
        // A journey supplied by class-string: interleave() must `new` it up
        // itself. Grabbing an anonymous class's name after building one is
        // just a convenient way to get a class-string in a test file.
        $stringJourney = new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('greet')->act(fn (Context $ctx): mixed => $ctx->remember('greeted', true)),
                ];
            }
        };
        $stringJourneyClass = $stringJourney::class;

        // A journey supplied already-built, whose constructor requires an
        // argument: if interleave() ever tried to `new` this one up again
        // (instead of using it as-is), building it would blow up immediately.
        $builtJourney = new class('B') extends Journey
        {
            public function __construct(private readonly string $label) {}

            public function steps(): array
            {
                return [
                    Step::make('note')->act(fn (Context $ctx): mixed => $ctx->remember('label', $this->label)),
                ];
            }
        };

        $this->interleave($stringJourneyClass, $builtJourney)->shuffles(0)->run();

        $this->addToAssertionCount(1);
    }

    public function test_the_overridable_hooks_stay_protected_not_private(): void
    {
        $trait = new ReflectionClass(RunsJourneys::class);

        foreach (['journey', 'interleave', 'journeyHttpDriver', 'wrapTrail'] as $method) {
            $this->assertTrue(
                $trait->getMethod($method)->isProtected(),
                sprintf('RunsJourneys::%s() must stay protected so a TestCase subclass can override it.', $method),
            );
        }
    }

    private function verbEchoJourney(): Journey
    {
        return new class extends Journey
        {
            public function steps(): array
            {
                return [
                    Step::make('register actor')
                        ->act(function (Context $ctx): void {
                            $ctx->actingAs(User::query()->create(['name' => 'ana']), 'ana');
                        }),

                    Step::make('every verb round-trips through its own method')
                        ->after('register actor')
                        ->act(function (Context $ctx): void {
                            $ctx->as('ana')->get('/verb-echo')->assertOk()->assertJson(['method' => 'GET']);
                            $ctx->as('ana')->post('/verb-echo')->assertOk()->assertJson(['method' => 'POST']);
                            $ctx->as('ana')->put('/verb-echo')->assertOk()->assertJson(['method' => 'PUT']);
                            $ctx->as('ana')->patch('/verb-echo')->assertOk()->assertJson(['method' => 'PATCH']);
                            $ctx->as('ana')->delete('/verb-echo')->assertOk()->assertJson(['method' => 'DELETE']);
                        }),
                ];
            }
        };
    }
}
