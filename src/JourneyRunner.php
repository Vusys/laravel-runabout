<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\InvariantViolationException;
use Vusys\Runabout\Exceptions\JourneyFailedException;
use Vusys\Runabout\Exceptions\OrderNotViableException;

final class JourneyRunner
{
    /**
     * Safety valve against a picker that keeps choosing repeatable steps and
     * never finishes: total executions per trail are capped at this multiple
     * of the step count (with a generous floor for tiny journeys).
     */
    private const int TICK_MULTIPLIER = 25;

    /**
     * @param  int  $repeatBias  Multiplies every repeatable step's pick weight;
     *                           above 1 the trail hunts idempotency and counter
     *                           bugs by re-running repeatable steps more often.
     *
     * @throws JourneyFailedException
     */
    public function run(Journey $journey, int $seed, bool $shuffle = true, ?HttpDriver $http = null, int $repeatBias = 1): Trail
    {
        return $this->runInterleaved([$journey], $seed, $shuffle, $http, $repeatBias);
    }

    /**
     * Run several journey instances merge-shuffled into one trail. Each
     * instance keeps its own context (values, history, actors); the trail's
     * randomizer and teardown stack are shared. See docs/interleave-design.md.
     *
     * @param  non-empty-list<Journey>  $journeys
     *
     * @throws JourneyFailedException
     */
    public function runInterleaved(array $journeys, int $seed, bool $shuffle = true, ?HttpDriver $http = null, int $repeatBias = 1): Trail
    {
        $mode = $shuffle ? ($repeatBias > 1 ? 'repeat-heavy' : 'shuffled') : 'canonical';
        $randomizer = new Randomizer(new Mt19937($seed));
        $instances = $this->instances($journeys, $randomizer, $http);

        return $this->trail($instances, $seed, $mode, function (Trail $trail) use ($instances, $shuffle, $randomizer, $repeatBias): void {
            $shuffle
                ? $this->runShuffled($instances, $randomizer, $trail, $repeatBias)
                : $this->runCanonical($instances, $trail);
        });
    }

    /**
     * Run one journey's steps in one explicit order (by declared index),
     * abandoning the order if a step is not enabled when its slot comes up.
     *
     * @param  list<int>  $order
     *
     * @throws OrderNotViableException when the order is not a valid trail
     * @throws JourneyFailedException
     */
    public function runOrder(Journey $journey, array $order, int $seed, ?HttpDriver $http = null): Trail
    {
        $randomizer = new Randomizer(new Mt19937($seed));
        $instances = $this->instances([$journey], $randomizer, $http);
        $instance = $instances[0];

        return $this->trail($instances, $seed, 'exhaustive', function (Trail $trail) use ($instance, $order): void {
            foreach ($order as $index) {
                $step = $instance->steps[$index];

                if (! $step->isEnabled($instance->context)) {
                    throw new OrderNotViableException($step->name());
                }

                $this->executeStep($instance, $step, $instance->invariants, $trail);
            }
        });
    }

    /**
     * @param  non-empty-list<Journey>  $journeys
     * @return non-empty-list<JourneyInstance>
     */
    private function instances(array $journeys, Randomizer $randomizer, ?HttpDriver $http): array
    {
        $deferred = new DeferredStack;
        $labelled = count($journeys) > 1;
        $instances = [];

        foreach ($journeys as $i => $journey) {
            $instances[] = new JourneyInstance(
                journey: $journey,
                label: $labelled ? chr(65 + $i) : null,
                steps: $this->validated($journey),
                // Collected once per trail so a stateful invariant (one that
                // tracks observations across steps) lives exactly as long as
                // the trail.
                invariants: $journey->invariants(),
                context: new Context($randomizer, $http, $deferred),
            );
        }

        return $instances;
    }

    /**
     * Shared harness: run the body, drain teardowns even on failure, and wrap
     * any failure with the trail that produced it.
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     * @param  'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'  $mode
     * @param  Closure(Trail): void  $body
     */
    private function trail(array $instances, int $seed, string $mode, Closure $body): Trail
    {
        $description = implode(' + ', array_map(fn (JourneyInstance $instance): string => $instance->describe(), $instances));
        $trail = new Trail($seed, $mode);

        $failure = null;

        try {
            $body($trail);
        } catch (OrderNotViableException $notViable) {
            $failure = $notViable;
        } catch (Throwable $caught) {
            $failure = JourneyFailedException::wrap($description, $trail, $caught);
        }

        // Every instance shares one stack, so draining any context unwinds
        // the whole trail's teardowns in reverse execution order.
        foreach ($instances[0]->context->drainDeferred() as $teardown) {
            try {
                $teardown();
            } catch (Throwable $caught) {
                // A teardown failure must never mask the primary failure.
                $failure ??= JourneyFailedException::wrap($description, $trail, $caught);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $trail;
    }

    /** @param non-empty-list<JourneyInstance> $instances */
    private function runCanonical(array $instances, Trail $trail): void
    {
        foreach ($instances as $instance) {
            foreach ($instance->steps as $step) {
                if (! $step->isEnabled($instance->context)) {
                    throw new InvalidJourneyException(sprintf(
                        'Step "%s" is not enabled when reached in declared order; the canonical order must be a valid trail. Check its when()/after() constraints.',
                        $step->name(),
                    ));
                }

                $this->executeStep($instance, $step, $this->mergedInvariants($instances), $trail);
            }
        }
    }

    /** @param non-empty-list<JourneyInstance> $instances */
    private function runShuffled(array $instances, Randomizer $randomizer, Trail $trail, int $repeatBias): void
    {
        $invariants = $this->mergedInvariants($instances);
        $ticks = 0;
        $totalSteps = array_sum(array_map(fn (JourneyInstance $instance): int => count($instance->steps), $instances));
        $maxTicks = max(100, $totalSteps * self::TICK_MULTIPLIER);

        while (($pending = $this->pending($instances)) !== []) {
            $enabled = $this->enabled($instances);

            if ($enabled === []) {
                throw new InvalidJourneyException(sprintf(
                    'Deadlock: no step is enabled but these steps have not run: %s. A when()/after() constraint is unsatisfiable from here.',
                    implode(', ', $pending),
                ));
            }

            if (++$ticks > $maxTicks) {
                throw new InvalidJourneyException(sprintf(
                    'Runaway trail: %d steps executed without completing the journey. Bound your repeatable() steps or loosen their preconditions.',
                    $maxTicks,
                ));
            }

            [$instance, $step] = $this->pick($enabled, $randomizer, $repeatBias);

            $this->executeStep($instance, $step, $invariants, $trail);
        }
    }

    /**
     * Weighted pick among the enabled steps. With unit weights and no bias
     * this consumes the randomizer identically to a uniform pick, so plain
     * shuffle trails are stable across versions.
     *
     * @param  non-empty-list<array{JourneyInstance, Step}>  $enabled
     * @return array{JourneyInstance, Step}
     */
    private function pick(array $enabled, Randomizer $randomizer, int $repeatBias): array
    {
        $weights = array_map(
            fn (array $pair): int => $pair[1]->pickWeight() * ($pair[1]->isRepeatable() ? $repeatBias : 1),
            $enabled,
        );

        $roll = $randomizer->getInt(1, array_sum($weights));

        foreach ($enabled as $index => $pair) {
            $roll -= $weights[$index];

            if ($roll <= 0) {
                return $pair;
            }
        }

        return $enabled[count($enabled) - 1];
    }

    /** @param list<Invariant> $invariants */
    private function executeStep(JourneyInstance $instance, Step $step, array $invariants, Trail $trail): void
    {
        $trail->record($instance->label === null ? $step->name() : sprintf('%s: %s', $instance->label, $step->name()));

        $step->execute($instance->context);

        foreach ($invariants as $invariant) {
            try {
                $invariant->check($instance->context);
            } catch (Throwable $caught) {
                throw InvariantViolationException::make($invariant, $step, $caught);
            }
        }

        $instance->context->recordRun($step->name());
    }

    /**
     * All instances' invariants, checked after every step of any instance —
     * a cross-tenant invariant declared on one journey polices them all.
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     * @return list<Invariant>
     */
    private function mergedInvariants(array $instances): array
    {
        return array_merge(...array_map(fn (JourneyInstance $instance): array => $instance->invariants, $instances));
    }

    /**
     * Names of steps that still have to run at least once, labelled when
     * interleaved, quoted for the deadlock message.
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     * @return list<string>
     */
    private function pending(array $instances): array
    {
        $pending = [];

        foreach ($instances as $instance) {
            foreach ($instance->steps as $step) {
                if ($instance->context->timesRan($step->name()) === 0) {
                    $name = $instance->label === null ? $step->name() : sprintf('%s: %s', $instance->label, $step->name());
                    $pending[] = '"'.$name.'"';
                }
            }
        }

        return $pending;
    }

    /**
     * @param  non-empty-list<JourneyInstance>  $instances
     * @return list<array{JourneyInstance, Step}>
     */
    private function enabled(array $instances): array
    {
        $enabled = [];

        foreach ($instances as $instance) {
            foreach ($instance->steps as $step) {
                if ($step->isEnabled($instance->context)) {
                    $enabled[] = [$instance, $step];
                }
            }
        }

        return $enabled;
    }

    /** @return list<Step> */
    private function validated(Journey $journey): array
    {
        $steps = $journey->steps();
        $names = array_map(fn (Step $step): string => $step->name(), $steps);

        if ($steps === []) {
            throw new InvalidJourneyException(sprintf('Journey %s defines no steps.', $journey::class));
        }

        if (count($names) !== count(array_unique($names))) {
            throw new InvalidJourneyException(sprintf('Journey %s has duplicate step names.', $journey::class));
        }

        foreach ($steps as $step) {
            foreach ($step->dependencies() as $dependency) {
                if (! in_array($dependency, $names, true)) {
                    throw new InvalidJourneyException(sprintf(
                        'Step "%s" declares after("%s") but no step has that name.',
                        $step->name(),
                        $dependency,
                    ));
                }
            }
        }

        return $steps;
    }
}
