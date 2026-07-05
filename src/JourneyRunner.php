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
        $steps = $this->validated($journey);
        $mode = $shuffle ? ($repeatBias > 1 ? 'repeat-heavy' : 'shuffled') : 'canonical';

        return $this->trail($journey, $steps, $seed, $mode, $http, function (array $steps, array $invariants, Context $context, Trail $trail) use ($shuffle, $repeatBias): void {
            $shuffle
                ? $this->runShuffled($steps, $invariants, $context, $trail, $repeatBias)
                : $this->runCanonical($steps, $invariants, $context, $trail);
        });
    }

    /**
     * Run the steps in one explicit order (by declared index), skipping the
     * order entirely if a step is not enabled when its slot comes up.
     *
     * @param  list<int>  $order
     *
     * @throws OrderNotViableException when the order is not a valid trail
     * @throws JourneyFailedException
     */
    public function runOrder(Journey $journey, array $order, int $seed, ?HttpDriver $http = null): Trail
    {
        $steps = $this->validated($journey);

        return $this->trail($journey, $steps, $seed, 'exhaustive', $http, function (array $steps, array $invariants, Context $context, Trail $trail) use ($order): void {
            foreach ($order as $index) {
                $step = $steps[$index];

                if (! $step->isEnabled($context)) {
                    throw new OrderNotViableException($step->name());
                }

                $this->executeStep($step, $invariants, $context, $trail);
            }
        });
    }

    /**
     * Shared harness: build the context, run the body, drain teardowns even
     * on failure, and wrap any failure with the trail that produced it.
     *
     * @param  list<Step>  $steps
     * @param  'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'  $mode
     * @param  Closure(list<Step>, list<Invariant>, Context, Trail): void  $body
     */
    private function trail(Journey $journey, array $steps, int $seed, string $mode, ?HttpDriver $http, Closure $body): Trail
    {
        // Collected once per trail so a stateful invariant (one that tracks
        // observations across steps) lives exactly as long as the trail.
        $invariants = $journey->invariants();
        $context = new Context(new Randomizer(new Mt19937($seed)), $http);
        $trail = new Trail($seed, $mode);

        $failure = null;

        try {
            $body($steps, $invariants, $context, $trail);
        } catch (OrderNotViableException $notViable) {
            $failure = $notViable;
        } catch (Throwable $caught) {
            $failure = JourneyFailedException::wrap($journey, $trail, $caught);
        }

        foreach ($context->drainDeferred() as $teardown) {
            try {
                $teardown();
            } catch (Throwable $caught) {
                // A teardown failure must never mask the primary failure.
                $failure ??= JourneyFailedException::wrap($journey, $trail, $caught);
            }
        }

        if ($failure !== null) {
            throw $failure;
        }

        return $trail;
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    private function runCanonical(array $steps, array $invariants, Context $context, Trail $trail): void
    {
        foreach ($steps as $step) {
            if (! $step->isEnabled($context)) {
                throw new InvalidJourneyException(sprintf(
                    'Step "%s" is not enabled when reached in declared order; the canonical order must be a valid trail. Check its when()/after() constraints.',
                    $step->name(),
                ));
            }

            $this->executeStep($step, $invariants, $context, $trail);
        }
    }

    /**
     * @param  list<Step>  $steps
     * @param  list<Invariant>  $invariants
     */
    private function runShuffled(array $steps, array $invariants, Context $context, Trail $trail, int $repeatBias = 1): void
    {
        $ticks = 0;
        $maxTicks = max(100, count($steps) * self::TICK_MULTIPLIER);

        while (($pending = $this->pending($steps, $context)) !== []) {
            $enabled = array_values(array_filter($steps, fn (Step $step): bool => $step->isEnabled($context)));

            if ($enabled === []) {
                throw new InvalidJourneyException(sprintf(
                    'Deadlock: no step is enabled but these steps have not run: %s. A when()/after() constraint is unsatisfiable from here.',
                    implode(', ', array_map(fn (Step $step): string => '"'.$step->name().'"', $pending)),
                ));
            }

            if (++$ticks > $maxTicks) {
                throw new InvalidJourneyException(sprintf(
                    'Runaway trail: %d steps executed without completing the journey. Bound your repeatable() steps or loosen their preconditions.',
                    $maxTicks,
                ));
            }

            $this->executeStep($this->pick($enabled, $context, $repeatBias), $invariants, $context, $trail);
        }
    }

    /**
     * Weighted pick among the enabled steps. With unit weights and no bias
     * this consumes the randomizer identically to a uniform pick, so plain
     * shuffle trails are stable across versions.
     *
     * @param  non-empty-list<Step>  $enabled
     */
    private function pick(array $enabled, Context $context, int $repeatBias): Step
    {
        $weights = array_map(
            fn (Step $step): int => $step->pickWeight() * ($step->isRepeatable() ? $repeatBias : 1),
            $enabled,
        );

        $roll = $context->randomInt(1, array_sum($weights));

        foreach ($enabled as $index => $step) {
            $roll -= $weights[$index];

            if ($roll <= 0) {
                return $step;
            }
        }

        return $enabled[count($enabled) - 1];
    }

    /** @param list<Invariant> $invariants */
    private function executeStep(Step $step, array $invariants, Context $context, Trail $trail): void
    {
        $trail->record($step->name());

        $step->execute($context);

        foreach ($invariants as $invariant) {
            try {
                $invariant->check($context);
            } catch (Throwable $caught) {
                throw InvariantViolationException::make($invariant, $step, $caught);
            }
        }

        $context->recordRun($step->name());
    }

    /**
     * Steps that still have to run at least once for the trail to be complete.
     *
     * @param  list<Step>  $steps
     * @return list<Step>
     */
    private function pending(array $steps, Context $context): array
    {
        return array_values(array_filter($steps, fn (Step $step): bool => $context->timesRan($step->name()) === 0));
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
