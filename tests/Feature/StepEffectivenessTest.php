<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Feature;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Routing\Router;
use Illuminate\Testing\TestResponse;
use RuntimeException;
use Symfony\Component\HttpFoundation\Response;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\PendingJourney;
use Vusys\Runabout\Step;
use Vusys\Runabout\Tests\Fixtures\DefaultHooksJourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\JourneyTestCase;
use Vusys\Runabout\Tests\Fixtures\Models\User;
use Vusys\Runabout\Trail;
use Vusys\Runabout\TrailCoverage;

/**
 * A journey can pass without its interesting steps ever having an effect.
 * noop() and rejected requests mark executions as ineffective, the coverage
 * report counts them, and requireEffect() fails a run where steps never
 * did anything.
 */
final class StepEffectivenessTest extends JourneyTestCase
{
    /** @param Router $router */
    #[\Override]
    protected function defineRoutes($router): void
    {
        $router->post('/accept', fn (): JsonResponse => new JsonResponse(['ok' => true]));
        $router->post('/reject', fn (): JsonResponse => new JsonResponse(['message' => 'invalid'], 422));
    }

    #[\Override]
    protected function journeyIsRejected(TestResponse $response): bool
    {
        return $response->isClientError();
    }

    public function test_noop_marks_the_execution_as_ineffective(): void
    {
        $trails = $this->trailsOf($this->journeyWith([
            Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
            Step::make('finds nothing')->act(fn (Context $ctx) => $ctx->noop('nothing pending')),
        ]));

        $this->assertTrue($trails[0]->tookEffect(0));
        $this->assertFalse($trails[0]->tookEffect(1));
        $this->assertNull($trails[0]->ineffectiveReason(0));
        $this->assertSame('nothing pending', $trails[0]->ineffectiveReason(1));
    }

    public function test_a_step_whose_requests_were_all_rejected_had_no_effect(): void
    {
        $coverage = $this->coverageOf($this->journeyWith([
            Step::make('all rejected')->act(function (Context $ctx): void {
                $ctx->as('ana')->postJson('/reject');
                $ctx->as('ana')->postJson('/reject');
            }),
            Step::make('one accepted')->act(function (Context $ctx): void {
                $ctx->as('ana')->postJson('/reject');
                $ctx->as('ana')->postJson('/accept');
            }),
            Step::make('no requests')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
        ]));

        $this->assertSame(['all rejected' => 0, 'one accepted' => 1, 'no requests' => 1], $coverage->effectiveRuns());
    }

    public function test_rejected_form_requests_count_too(): void
    {
        $coverage = $this->coverageOf($this->journeyWith([
            Step::make('form rejected')->act(fn (Context $ctx): TestResponse => $ctx->as('ana')->post('/reject')),
        ]));

        $this->assertSame(['form rejected' => 0], $coverage->effectiveRuns());
    }

    public function test_rejection_rules_stack_on_the_test_cases_own(): void
    {
        $journey = $this->journeyWith([
            Step::make('rejected by the test case')->act(fn (Context $ctx): TestResponse => $ctx->as('ana')->postJson('/reject')),
            Step::make('rejected by the extra rule')->act(fn (Context $ctx): TestResponse => $ctx->as('ana')->postJson('/accept')),
        ]);

        $coverage = new TrailCoverage([$journey]);
        $this->journey($journey)
            ->withRejectionRule(fn (TestResponse $response): bool => $response->json('ok') === true)
            ->shuffles(0)
            ->onTrail($coverage->record(...))
            ->run();

        $this->assertSame(['rejected by the test case' => 0, 'rejected by the extra rule' => 0], $coverage->effectiveRuns());
    }

    public function test_a_rejection_rule_without_an_http_driver_is_a_no_op(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true))];
            }
        };

        (new PendingJourney($journey, fn (Closure $trail) => $trail()))
            ->withRejectionRule(fn (): bool => true)
            ->shuffles(0)
            ->run();

        $this->addToAssertionCount(1);
    }

    public function test_requests_invariants_make_are_not_counted(): void
    {
        $coverage = $this->coverageOf($this->journeyWith(
            [Step::make('no requests')->act(fn (Context $ctx): mixed => $ctx->remember('did', true))],
            [Invariant::make('looks', function (Context $ctx): void {
                $ctx->as('ana')->postJson('/reject');
            })],
        ));

        $this->assertSame(['no requests' => 1], $coverage->effectiveRuns());
    }

    public function test_the_coverage_report_shows_effect_counts_only_once_something_had_no_effect(): void
    {
        $quiet = $this->coverageOf($this->journeyWith([Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true))]));
        $noisy = $this->coverageOf($this->journeyWith([
            Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
            Step::make('finds nothing')->act(fn (Context $ctx) => $ctx->noop('nothing pending')),
        ]));

        $this->assertStringContainsString('  acts  1 runs in 1/1 trails'."\n", $quiet->describe());
        $this->assertStringContainsString('  acts           1 runs in 1/1 trails, 1 effective'."\n", $noisy->describe());
        $this->assertStringContainsString('  finds nothing  1 runs in 1/1 trails, 0 effective (no effect: nothing pending)'."\n", $noisy->describe());
    }

    public function test_require_effect_fails_a_run_where_a_step_never_took_effect(): void
    {
        $journey = $this->journey($this->journeyWith([
            Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
            Step::make('finds nothing')->repeatable(max: 2)->act(fn (Context $ctx) => $ctx->noop()),
        ]))->shuffles(2)->requireEffect();

        $message = $this->messageOf($journey);

        $this->assertStringContainsString('passed, but not every step took effect: "finds nothing" took effect in 0 of ', $message);
        $this->assertStringNotContainsString('"acts"', $message);
        $this->assertStringNotContainsString('required', $message);
    }

    public function test_an_optional_step_that_never_ran_never_took_effect(): void
    {
        $journey = $this->journey($this->journeyWith([
            Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
            Step::make('never eligible')->optional()->when(fn (): bool => false)->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
        ]))->shuffles(1)->requireEffect();

        $this->assertStringContainsString('"never eligible" took effect in 0 of 0 runs', $this->messageOf($journey));
    }

    public function test_overlapping_requirements_report_a_step_once(): void
    {
        $journey = $this->journey($this->journeyWith([
            Step::make('finds nothing')->act(fn (Context $ctx) => $ctx->noop()),
        ]))->shuffles(0)->requireEffect()->requireEffect('finds nothing');

        $this->assertSame(1, substr_count($this->messageOf($journey), '"finds nothing" took effect'));
    }

    public function test_require_effect_passes_once_every_step_took_effect(): void
    {
        $this->journey($this->journeyWith([
            Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true)),
            Step::make('sometimes')->repeatable(max: 3, min: 2)->act(fn (Context $ctx): mixed => $ctx->timesRan('sometimes') === 0 ? $ctx->remember('did', true) : $ctx->noop()),
        ]))->shuffles(2)->requireEffect()->run();

        $this->addToAssertionCount(1);
    }

    public function test_require_effect_with_a_share_checks_only_that_step(): void
    {
        // The canonical trail runs "half" once (it takes effect); the shuffled
        // trail runs it twice (once with effect): 2 of 3 runs in all.
        $journey = fn (): PendingJourney => $this->journey($this->journeyWith([
            Step::make('finds nothing')->act(fn (Context $ctx) => $ctx->noop()),
            Step::make('half')->repeatable(max: 2, min: 2)->act(fn (Context $ctx): mixed => $ctx->timesRan('half') === 0 ? $ctx->remember('did', true) : $ctx->noop()),
        ]))->shuffles(1);

        $journey()->requireEffect('half', atLeast: 0.6)->run();
        $journey()->requireEffect('half', atLeast: 2 / 3)->run();

        // 0.7 of 3 runs rounds up: all 3 must take effect.
        $this->assertStringContainsString('"half" took effect in 2 of 3 runs (at least 3 required)', $this->messageOf($journey()->requireEffect('half', atLeast: 0.7)));
    }

    public function test_require_effect_checks_each_interleaved_instance(): void
    {
        $quiet = $this->journeyWith([Step::make('acts')->act(fn (Context $ctx) => $ctx->noop())]);
        $busy = $this->journeyWith([Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true))]);

        $message = $this->messageOf($this->interleave($busy, $quiet)->shuffles(0)->requireEffect('acts'));

        $this->assertStringContainsString('"B: acts" took effect in 0 of 1 runs', $message);
        $this->assertStringNotContainsString('"A: acts"', $message);
    }

    public function test_require_effect_rejects_an_unknown_step(): void
    {
        $this->expectException(InvalidJourneyException::class);
        $this->expectExceptionMessage('requireEffect() names step "nope", but no journey in this run declares it.');

        $this->journey($this->journeyWith([Step::make('acts')->act(fn (Context $ctx): mixed => $ctx->remember('did', true))]))->shuffles(0)->requireEffect('nope')->run();
    }

    public function test_the_default_rejects_nothing(): void
    {
        /** @var TestResponse<Response> $response */
        $response = TestResponse::fromBaseResponse(new HttpResponse('', 422));

        $this->assertFalse((new DefaultHooksJourneyTestCase('defaultIsRejected'))->defaultIsRejected($response));
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    private function journeyWith(array $steps, array $invariants = []): Journey
    {
        $ana = User::query()->firstOrCreate(['name' => 'ana']);

        return new class($ana, $steps, $invariants) extends Journey
        {
            /**
             * @param  list<Step>  $steps
             * @param  list<Invariant>  $invariants
             */
            public function __construct(private readonly User $ana, private readonly array $steps, private readonly array $invariants) {}

            public function actors(): array
            {
                return ['ana' => $this->ana];
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

    /** @return list<Trail> */
    private function trailsOf(Journey $journey): array
    {
        $trails = [];
        $this->journey($journey)->shuffles(0)->onTrail(function (Trail $trail) use (&$trails): void {
            $trails[] = $trail;
        })->run();

        return $trails;
    }

    private function coverageOf(Journey $journey): TrailCoverage
    {
        $coverage = new TrailCoverage([$journey]);
        $this->journey($journey)->shuffles(0)->onTrail($coverage->record(...))->run();

        return $coverage;
    }

    private function messageOf(PendingJourney $journey): string
    {
        try {
            $journey->run();
        } catch (RuntimeException $failure) {
            return $failure->getMessage();
        }

        $this->fail('The run was expected to fail.');
    }
}
