<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Unit;

use ArrayObject;
use Closure;
use PHPUnit\Framework\Assert;
use PHPUnit\Framework\TestCase;
use Vusys\Runabout\Context;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Invariant;
use Vusys\Runabout\Journey;
use Vusys\Runabout\JourneyRunner;
use Vusys\Runabout\Step;

final class AroundStepTest extends TestCase
{
    public function test_around_step_wraps_every_step_execution(): void
    {
        /** @var ArrayObject<int, string> $log */
        $log = new ArrayObject;

        $journey = new class($log) extends Journey
        {
            /** @param ArrayObject<int, string> $log */
            public function __construct(private readonly ArrayObject $log) {}

            public function steps(): array
            {
                $act = fn (string $name): Closure => function () use ($name): void {
                    $this->log[] = $name;
                };

                return [
                    Step::make('a')->act($act('a')),
                    Step::make('b')->act($act('b')),
                ];
            }

            public function aroundStep(Closure $execution, Context $context): void
            {
                $this->log[] = 'in';
                $execution();
                $this->log[] = 'out';
            }
        };

        (new JourneyRunner)->run($journey, seed: 1, shuffle: false);

        $this->assertSame(
            ['in', 'a', 'out', 'in', 'b', 'out'],
            array_values($log->getArrayCopy()),
        );
    }

    public function test_around_step_wraps_this_journeys_invariant_checks_too(): void
    {
        /** @var ArrayObject<string, string|null> $world */
        $world = new ArrayObject(['tenant' => null]);

        $journey = new class($world) extends Journey
        {
            /** @param ArrayObject<string, string|null> $world */
            public function __construct(private readonly ArrayObject $world) {}

            public function steps(): array
            {
                return [
                    Step::make('drift')->act(function (): void {
                        // Simulate another actor changing the shared
                        // environment after this journey's wrapper ran.
                        $this->world['tenant'] = 'someone else';
                    }),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('sees its own tenant', function (): void {
                        Assert::assertSame('me', $this->world['tenant']);
                    }),
                ];
            }

            public function aroundStep(Closure $execution, Context $context): void
            {
                $this->world['tenant'] = 'me';
                $execution();
            }
        };

        // Passes only if the invariant batch is re-wrapped after the act
        // drifted the environment; the act itself ran inside a wrapper that
        // had already set the tenant.
        (new JourneyRunner)->run($journey, seed: 1, shuffle: false);

        $this->assertSame('me', $world['tenant']);
    }

    public function test_interleaved_instances_each_act_inside_their_own_environment(): void
    {
        /** @var ArrayObject<string, string|null> $world */
        $world = new ArrayObject(['tenant' => null]);

        $journeys = [$this->tenantJourney('alpha', $world), $this->tenantJourney('beta', $world)];

        foreach (range(1, 10) as $seed) {
            (new JourneyRunner)->runInterleaved($journeys, $seed);
        }

        // The assertions live inside the acts and invariants; reaching here
        // means no step or invariant ever observed the other tenant.
        $this->assertContains($world['tenant'], ['alpha', 'beta']);
    }

    public function test_a_wrapper_that_never_invokes_the_execution_is_rejected(): void
    {
        $journey = new class extends Journey
        {
            public function steps(): array
            {
                return [Step::make('skipped')];
            }

            public function aroundStep(Closure $execution, Context $context): void
            {
                // Forgets to call $execution().
            }
        };

        try {
            (new JourneyRunner)->run($journey, seed: 1, shuffle: false);
            $this->fail('Expected the wrapper to be rejected.');
        } catch (JourneyFailedException $e) {
            $this->assertStringContainsString('aroundStep() returned without invoking the execution closure', $e->getMessage());
        }
    }

    /**
     * A journey whose acts and invariants only pass when the shared "session"
     * carries its own tenant — the shape session-state tenancy takes in an
     * interleaved trail.
     *
     * @param  ArrayObject<string, string|null>  $world
     */
    private function tenantJourney(string $tenant, ArrayObject $world): Journey
    {
        return new class($tenant, $world) extends Journey
        {
            /** @param ArrayObject<string, string|null> $world */
            public function __construct(
                private readonly string $tenant,
                private readonly ArrayObject $world,
            ) {}

            public function steps(): array
            {
                $assertOwnTenant = function (): void {
                    Assert::assertSame(
                        $this->tenant,
                        $this->world['tenant'],
                        'An act observed another tenant\'s environment.',
                    );
                };

                return [
                    Step::make('open')->act($assertOwnTenant),
                    Step::make('write')->repeatable(max: 3)->act($assertOwnTenant),
                ];
            }

            public function invariants(): array
            {
                return [
                    Invariant::make('invariants run as their own tenant', function (): void {
                        Assert::assertSame(
                            $this->tenant,
                            $this->world['tenant'],
                            'An invariant observed another tenant\'s environment.',
                        );
                    }),
                ];
            }

            public function aroundStep(Closure $execution, Context $context): void
            {
                $this->world['tenant'] = $this->tenant;
                $execution();
            }
        };
    }
}
