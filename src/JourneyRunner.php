<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Random\Engine\Mt19937;
use Random\Randomizer;
use Throwable;
use Vusys\Runabout\Exceptions\InvalidJourneyException;
use Vusys\Runabout\Exceptions\InvariantViolationException;
use Vusys\Runabout\Exceptions\JourneyFailedException;

final class JourneyRunner
{
    /**
     * Safety valve against a picker that keeps choosing repeatable steps and
     * never finishes: total executions per trail are capped at this multiple
     * of the step count (with a generous floor for tiny journeys).
     */
    private const int TICK_MULTIPLIER = 25;

    /** @throws JourneyFailedException */
    public function run(Journey $journey, int $seed, bool $shuffle = true, ?HttpDriver $http = null): Trail
    {
        $steps = $this->validated($journey);
        // Collected once per trail so a stateful invariant (one that tracks
        // observations across steps) lives exactly as long as the trail.
        $invariants = $journey->invariants();
        $context = new Context(new Randomizer(new Mt19937($seed)), $http);
        $trail = new Trail($seed, $shuffle);

        $failure = null;

        try {
            $shuffle
                ? $this->runShuffled($steps, $invariants, $context, $trail)
                : $this->runCanonical($steps, $invariants, $context, $trail);
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

        if ($failure instanceof JourneyFailedException) {
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
    private function runShuffled(array $steps, array $invariants, Context $context, Trail $trail): void
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

            $step = $enabled[$context->randomInt(0, count($enabled) - 1)];

            $this->executeStep($step, $invariants, $context, $trail);
        }
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
