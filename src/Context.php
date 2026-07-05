<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Random\Randomizer;

/**
 * The mutable bag threaded through a single trail: remembered values, run
 * history, deferred teardowns, and the seeded source of all randomness.
 */
final class Context
{
    /** @var array<string, mixed> */
    private array $values = [];

    /** @var array<string, int> */
    private array $runs = [];

    /** @var list<Closure> */
    private array $deferred = [];

    public function __construct(private readonly Randomizer $randomizer) {}

    public function remember(string $key, mixed $value): mixed
    {
        $this->values[$key] = $value;

        return $value;
    }

    public function get(string $key, mixed $default = null): mixed
    {
        return $this->values[$key] ?? $default;
    }

    public function has(string $key): bool
    {
        return array_key_exists($key, $this->values);
    }

    public function forget(string $key): void
    {
        unset($this->values[$key]);
    }

    /**
     * All randomness inside steps must come from these methods (or the
     * Randomizer itself) so that a seed reproduces the trail exactly.
     */
    public function randomInt(int $min, int $max): int
    {
        return $this->randomizer->getInt($min, $max);
    }

    /**
     * @template T
     *
     * @param  non-empty-list<T>  $options
     * @return T
     */
    public function pick(array $options): mixed
    {
        return $options[$this->randomizer->getInt(0, count($options) - 1)];
    }

    public function randomizer(): Randomizer
    {
        return $this->randomizer;
    }

    public function timesRan(string $step): int
    {
        return $this->runs[$step] ?? 0;
    }

    /**
     * Whether the step has completed a previous execution. Inside a step's own
     * assertions this is false on the first run and true on repeats, which is
     * what lets a repeatable step change its assertions.
     */
    public function ranBefore(string $step): bool
    {
        return $this->timesRan($step) > 0;
    }

    /** Register cleanup to run LIFO at the end of the trail, even on failure. */
    public function defer(Closure $fn): void
    {
        $this->deferred[] = $fn;
    }

    /** @internal */
    public function recordRun(string $step): void
    {
        $this->runs[$step] = $this->timesRan($step) + 1;
    }

    /**
     * @internal
     *
     * @return list<Closure> in LIFO order
     */
    public function drainDeferred(): array
    {
        $deferred = array_reverse($this->deferred);
        $this->deferred = [];

        return $deferred;
    }
}
