<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Vusys\Runabout\Exceptions\InvalidJourneyException;

final class Step
{
    private ?Closure $act = null;

    /** @var list<Closure(Context): mixed> */
    private array $assertions = [];

    /** @var list<Closure(Context): bool> */
    private array $preconditions = [];

    /** @var list<string> */
    private array $after = [];

    private int $maxRuns = 1;

    private int $weight = 1;

    private ?Closure $teardown = null;

    private function __construct(private readonly string $name) {}

    public static function make(string $name): self
    {
        return new self($name);
    }

    /** @param Closure(Context): mixed $fn */
    public function act(Closure $fn): self
    {
        $this->act = $fn;

        return $this;
    }

    /** @param Closure(Context): mixed $fn Return values are ignored. May be called multiple times to add several assertions. */
    public function assert(Closure $fn): self
    {
        $this->assertions[] = $fn;

        return $this;
    }

    /** @param Closure(Context): bool $fn The step is only eligible to run while this returns true. */
    public function when(Closure $fn): self
    {
        $this->preconditions[] = $fn;

        return $this;
    }

    /** Sugar for a precondition: this step only becomes eligible once the named steps have each run at least once. */
    public function after(string ...$steps): self
    {
        array_push($this->after, ...$steps);

        return $this;
    }

    /** @param int|null $max Maximum executions per trail; null means unlimited (the trail still ends once every step has run). */
    public function repeatable(?int $max = null): self
    {
        $this->maxRuns = $max ?? PHP_INT_MAX;

        return $this;
    }

    /**
     * How strongly the shuffled picker favours this step relative to the
     * others (default 1). A step with weight 3 is picked three times as
     * often as a weight-1 step whenever both are enabled.
     */
    public function weight(int $weight): self
    {
        if ($weight < 1) {
            throw new InvalidJourneyException(sprintf('Step "%s" needs a weight of at least 1, got %d.', $this->name, $weight));
        }

        $this->weight = $weight;

        return $this;
    }

    /** @param Closure(Context): void $fn Registered per execution, run LIFO at the end of the trail, even on failure. */
    public function teardown(Closure $fn): self
    {
        $this->teardown = $fn;

        return $this;
    }

    public function name(): string
    {
        return $this->name;
    }

    /** @return list<string> */
    public function dependencies(): array
    {
        return $this->after;
    }

    public function isRepeatable(): bool
    {
        return $this->maxRuns > 1;
    }

    public function pickWeight(): int
    {
        return $this->weight;
    }

    public function isEnabled(Context $context): bool
    {
        if ($context->timesRan($this->name) >= $this->maxRuns) {
            return false;
        }

        foreach ($this->after as $dependency) {
            if (! $context->ranBefore($dependency)) {
                return false;
            }
        }

        foreach ($this->preconditions as $precondition) {
            if (! $precondition($context)) {
                return false;
            }
        }

        return true;
    }

    public function execute(Context $context): void
    {
        if ($this->teardown instanceof Closure) {
            $teardown = $this->teardown;
            $context->defer(fn () => $teardown($context));
        }

        if ($this->act instanceof Closure) {
            ($this->act)($context);
        }

        foreach ($this->assertions as $assertion) {
            $assertion($context);
        }
    }
}
