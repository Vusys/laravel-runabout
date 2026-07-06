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
     * instance keeps its own context (values, history, actors); the teardown
     * stack is shared. The trail seed drives one picker stream (order
     * decisions) and a fresh data stream per execution, so a step's draws
     * depend only on which execution it is. See docs/interleave-design.md and
     * docs/shrinking-design.md.
     *
     * @param  non-empty-list<Journey>  $journeys
     *
     * @throws JourneyFailedException
     */
    public function runInterleaved(array $journeys, int $seed, bool $shuffle = true, ?HttpDriver $http = null, int $repeatBias = 1): Trail
    {
        $mode = $shuffle ? ($repeatBias > 1 ? 'repeat-heavy' : 'shuffled') : 'canonical';
        $instances = $this->instances($journeys, $seed, $http);
        $picker = $this->pickerStream($seed);

        return $this->trail($instances, $seed, $mode, function (Trail $trail) use ($instances, $shuffle, $picker, $seed, $repeatBias): void {
            $shuffle
                ? $this->runShuffled($instances, $picker, $trail, $seed, $repeatBias)
                : $this->runCanonical($instances, $trail, $seed);
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
        $instances = $this->instances([$journey], $seed, $http);
        $instance = $instances[0];

        return $this->trail($instances, $seed, 'exhaustive', function (Trail $trail) use ($instances, $instance, $order, $seed): void {
            foreach ($order as $index) {
                $step = $instance->steps[$index];

                if (! $step->isEnabled($instance->context)) {
                    throw new OrderNotViableException($step->name());
                }

                $this->executeStep($instance, $step, $instances, $trail, $seed, $instance->context->timesRan($step->name()) + 1);
            }
        });
    }

    /**
     * Replay an explicit list of execution tokens against fresh instances —
     * the substrate for shrinking and RUNABOUT_TRAIL. Each token names an
     * instance (by label), a step (by name), and the run index that keys its
     * data stream, so surviving executions reproduce their draws verbatim. A
     * token whose step is not enabled when its slot comes up (a removed
     * after()/when() dependency) makes the whole order non-viable, exactly as
     * exhaustive mode does — a rejected candidate, not a failed test.
     *
     * @param  non-empty-list<Journey>  $journeys
     * @param  list<TrailToken>  $tokens
     *
     * @throws OrderNotViableException when a token is not runnable in its slot
     * @throws JourneyFailedException
     */
    public function runTokens(array $journeys, int $seed, array $tokens, ?HttpDriver $http = null): Trail
    {
        $instances = $this->instances($journeys, $seed, $http);

        $byLabel = [];
        foreach ($instances as $instance) {
            $byLabel[$instance->label ?? ''] = $instance;
        }

        return $this->trail($instances, $seed, 'replayed', function (Trail $trail) use ($byLabel, $instances, $tokens, $seed): void {
            foreach ($tokens as $token) {
                $instance = $byLabel[$token->label ?? ''] ?? null;

                if ($instance === null) {
                    throw new OrderNotViableException(sprintf('%s (unknown instance)', $token->labelled()));
                }

                $step = $this->stepNamed($instance, $token->step);

                if (! $step instanceof Step) {
                    throw new OrderNotViableException(sprintf('%s (unknown step)', $token->labelled()));
                }

                if (! $step->isEnabled($instance->context)) {
                    throw new OrderNotViableException($token->labelled());
                }

                $this->executeStep($instance, $step, $instances, $trail, $seed, $token->run);
            }
        });
    }

    /**
     * @param  non-empty-list<Journey>  $journeys
     * @return non-empty-list<JourneyInstance>
     */
    private function instances(array $journeys, int $seed, ?HttpDriver $http): array
    {
        $deferred = new DeferredStack;
        $labelled = count($journeys) > 1;
        $instances = [];

        foreach ($journeys as $i => $journey) {
            $label = $labelled ? chr(65 + $i) : null;

            // Placeholder stream: the runner installs a real per-execution
            // stream before any step runs, so this is only ever consulted by a
            // draw made outside an execution (which no correct journey does).
            $context = new Context($this->executionStream($seed, $label, '__init__', 0), $http, $deferred);

            $this->registerActors($journey, $context);

            $instances[] = new JourneyInstance(
                journey: $journey,
                label: $label,
                steps: $this->validated($journey),
                // Collected once per trail so a stateful invariant (one that
                // tracks observations across steps) lives exactly as long as
                // the trail.
                invariants: $journey->invariants(),
                context: $context,
            );
        }

        return $instances;
    }

    /**
     * Shared harness: run the body, drain teardowns even on failure, and wrap
     * any failure with the trail that produced it.
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     * @param  'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'|'replayed'  $mode
     * @param  Closure(Trail): void  $body
     */
    private function trail(array $instances, int $seed, string $mode, Closure $body): Trail
    {
        $description = implode(' + ', array_map(fn (JourneyInstance $instance): string => $instance->describe(), $instances));
        $trail = new Trail($seed, $mode);

        $failure = null;

        try {
            $this->checkBaseline($instances, $seed);
            $body($trail);
        } catch (OrderNotViableException $notViable) {
            $failure = $notViable;
        } catch (Throwable $caught) {
            $failure = JourneyFailedException::wrap($description, $trail, $caught);
        }

        // Teardowns drain against a dedicated trail-end stream so their draws,
        // too, are position-independent. Every instance shares one stack, so
        // draining any context unwinds the whole trail's teardowns in reverse.
        foreach ($instances as $instance) {
            $instance->context->useSource(new StreamDrawSource($this->teardownStream($seed, $instance->label)));
        }

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

    /**
     * @param  non-empty-list<JourneyInstance>  $instances
     */
    private function runCanonical(array $instances, Trail $trail, int $seed): void
    {
        foreach ($instances as $instance) {
            foreach ($instance->steps as $step) {
                if (! $step->isEnabled($instance->context)) {
                    throw new InvalidJourneyException(sprintf(
                        'Step "%s" is not enabled when reached in declared order; the canonical order must be a valid trail. Check its when()/after() constraints.',
                        $step->name(),
                    ));
                }

                $this->executeStep($instance, $step, $instances, $trail, $seed, $instance->context->timesRan($step->name()) + 1);
            }
        }
    }

    /**
     * @param  non-empty-list<JourneyInstance>  $instances
     */
    private function runShuffled(array $instances, Randomizer $picker, Trail $trail, int $seed, int $repeatBias): void
    {
        $ticks = 0;
        $minTotal = array_sum(array_map(
            fn (JourneyInstance $instance): int => array_sum(array_map(fn (Step $step): int => $step->minRuns(), $instance->steps)),
            $instances,
        ));
        $maxTicks = max(100, $minTotal * self::TICK_MULTIPLIER);

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

            [$instance, $step] = $this->pick($enabled, $picker, $repeatBias);

            $this->executeStep($instance, $step, $instances, $trail, $seed, $instance->context->timesRan($step->name()) + 1);
        }
    }

    /**
     * Weighted pick among the enabled steps against the trail's picker stream.
     *
     * @param  non-empty-list<array{JourneyInstance, Step}>  $enabled
     * @return array{JourneyInstance, Step}
     */
    private function pick(array $enabled, Randomizer $picker, int $repeatBias): array
    {
        $weights = array_map(
            fn (array $pair): int => $pair[1]->pickWeight() * ($pair[1]->isRepeatable() ? $repeatBias : 1),
            $enabled,
        );

        $roll = $picker->getInt(1, array_sum($weights));

        foreach ($enabled as $index => $pair) {
            $roll -= $weights[$index];

            if ($roll <= 0) {
                return $pair;
            }
        }

        return $enabled[count($enabled) - 1];
    }

    /**
     * Execute one step of one instance, then check every instance's
     * invariants — a cross-tenant invariant declared on one journey polices
     * them all. The step and every invariant check run against this
     * execution's data stream (keyed by run index, so position-independent);
     * the step runs inside the acting instance's aroundStep() wrapper, each
     * invariant batch inside its own instance's wrapper.
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     * @param  int  $runIndex  The 1-based run index that keys this execution's data stream.
     * @param  list<int>|null  $forcedDraws  Values to force for this execution (value shrinking); null draws from the stream.
     */
    private function executeStep(JourneyInstance $instance, Step $step, array $instances, Trail $trail, int $seed, int $runIndex, ?array $forcedDraws = null): void
    {
        $source = $this->executionSource($seed, $instance->label, $step->name(), $runIndex, $forcedDraws);

        $trail->record($instance->label, $step->name(), $runIndex);
        $instance->context->useSource($source);

        try {
            $this->wrapped($instance, fn () => $step->execute($instance->context));

            foreach ($instances as $owner) {
                if ($owner->invariants === []) {
                    continue;
                }

                // Invariants draw from the acting execution's source, whichever
                // instance owns them, so a stateful invariant's randomness is
                // position-independent too.
                $owner->context->useSource($source);

                $this->wrapped($owner, function () use ($owner, $instance, $step): void {
                    foreach ($owner->invariants as $invariant) {
                        try {
                            $invariant->check($owner->context);
                        } catch (Throwable $caught) {
                            throw InvariantViolationException::make(
                                $owner->labelled($invariant->name()),
                                $instance->labelled($step->name()),
                                $caught,
                            );
                        }
                    }
                });
            }
        } finally {
            // Record what was drawn (even if the step or an invariant threw) so
            // the value shrinker has this execution's baseline.
            $trail->attachDraws($source->draws(), $source->isOpaque());
        }

        $instance->context->recordRun($step->name());
    }

    /**
     * Check the invariants that opted into a baseline (Invariant::fromStart())
     * once before any step runs, so a state invariant can observe a row that
     * existed before the journey in its true initial state. Each runs inside
     * its owning instance's aroundStep() wrapper, against a dedicated baseline
     * stream; a violation here is a normal failure (reported "before any step
     * ran").
     *
     * @param  non-empty-list<JourneyInstance>  $instances
     */
    private function checkBaseline(array $instances, int $seed): void
    {
        foreach ($instances as $owner) {
            $baseline = array_values(array_filter(
                $owner->invariants,
                fn (Invariant $invariant): bool => $invariant->checksAtStart(),
            ));

            if ($baseline === []) {
                continue;
            }

            $owner->context->useSource(new StreamDrawSource($this->baselineStream($seed, $owner->label)));

            $this->wrapped($owner, function () use ($owner, $baseline): void {
                foreach ($baseline as $invariant) {
                    try {
                        $invariant->check($owner->context);
                    } catch (Throwable $caught) {
                        throw InvariantViolationException::make(
                            $owner->labelled($invariant->name()),
                            $owner->labelled('(trail start)'),
                            $caught,
                        );
                    }
                }
            });
        }
    }

    /**
     * Run an execution inside the instance's aroundStep() hook, guarding
     * against an override that forgets to invoke it — a silently skipped
     * step would make the journey test less than it claims.
     */
    private function wrapped(JourneyInstance $instance, Closure $execution): void
    {
        $ran = false;

        $instance->journey->aroundStep(function () use (&$ran, $execution): void {
            $ran = true;
            $execution();
        }, $instance->context);

        if (! $ran) {
            throw new InvalidJourneyException(sprintf(
                '%s::aroundStep() returned without invoking the execution closure it was given.',
                $instance->journey::class,
            ));
        }
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
                if ($instance->context->timesRan($step->name()) < $step->minRuns()) {
                    $pending[] = '"'.$instance->labelled($step->name()).'"';
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

    private function stepNamed(JourneyInstance $instance, string $name): ?Step
    {
        foreach ($instance->steps as $step) {
            if ($step->name() === $name) {
                return $step;
            }
        }

        return null;
    }

    /**
     * The picker stream for a trail: derived from the trail seed alone, so
     * order decisions live here and depend on nothing else.
     */
    private function pickerStream(int $seed): Randomizer
    {
        return new Randomizer(new Mt19937(crc32($seed.'|__picker__')));
    }

    /**
     * One execution's data stream: derived from the trail seed plus the
     * execution's identity (instance label, step name, run index), so the nth
     * run of a step draws the same values wherever it lands in the trail.
     */
    private function executionStream(int $seed, ?string $label, string $step, int $run): Randomizer
    {
        return new Randomizer(new Mt19937(crc32(sprintf('%d|%s|%s|%d', $seed, $label ?? '', $step, $run))));
    }

    /**
     * The draw source for an execution: a recording stream source normally, or
     * a scripted source (forced values, stream fallback) during value shrinking.
     *
     * @param  list<int>|null  $forcedDraws
     */
    private function executionSource(int $seed, ?string $label, string $step, int $run, ?array $forcedDraws): DrawSource
    {
        $stream = $this->executionStream($seed, $label, $step, $run);

        return $forcedDraws === null
            ? new StreamDrawSource($stream)
            : new ScriptedDrawSource($forcedDraws, $stream);
    }

    /** The trail-end stream that teardowns draw from. */
    private function teardownStream(int $seed, ?string $label): Randomizer
    {
        return new Randomizer(new Mt19937(crc32(sprintf('%d|__teardown__|%s', $seed, $label ?? ''))));
    }

    /** The trail-start stream that baseline invariant checks draw from. */
    private function baselineStream(int $seed, ?string $label): Randomizer
    {
        return new Randomizer(new Mt19937(crc32(sprintf('%d|__baseline__|%s', $seed, $label ?? ''))));
    }

    /**
     * Register the journey's declared actors on the trail's context, so a step
     * can reach them with $ctx->as(...) without a setup step. The name and user
     * types are enforced by Context::actingAs().
     */
    private function registerActors(Journey $journey, Context $context): void
    {
        foreach ($journey->actors() as $name => $user) {
            $context->actingAs($user, $name);
        }
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
