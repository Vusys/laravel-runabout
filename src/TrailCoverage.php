<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Vusys\Runabout\Execution\JourneyRunner;

/**
 * Aggregates completed trails into a coverage summary: how often each step
 * ran, how many distinct orderings the run explored, and which orderings of
 * step pairs were never observed. Feed it trails through onTrail() — or set
 * RUNABOUT_COVERAGE=1 to have every run print one automatically — and read
 * the result with describe(). Answers the question a green run leaves open:
 * is the shuffle count actually buying ordering coverage?
 */
final class TrailCoverage
{
    private const string PAIR_GLUE = "\x00";

    private int $trails = 0;

    /** @var array<string, int> mode => trails observed in that mode */
    private array $modes = [];

    /** @var array<string, int> ordering signature => trails with that exact ordering */
    private array $orderings = [];

    /** @var array<string, int> step => total executions across all trails */
    private array $runs = [];

    /** @var array<string, int> step => executions that had an effect */
    private array $effective = [];

    /** @var array<string, array<string, string>> step => reasons given to noop(), as a set */
    private array $noopReasons = [];

    /** Whether any recorded execution had no effect, so the report shows effect counts. */
    private bool $sawIneffective = false;

    /** @var array<string, int> step => trails the step appeared in */
    private array $appearances = [];

    /** @var array<string, int> "before\x00after" => trails where some run of before preceded some run of after */
    private array $pairs = [];

    /** @var array<string, string> "before\x00after" => itself, for pairs the journeys' after() constraints make impossible */
    private array $impossible = [];

    /**
     * @param  list<Journey>  $journeys  The journeys whose trails are recorded, in run order. A step can
     *                                   never run before a once-only step it is (transitively) after(),
     *                                   so those pairs are left out of the unseen list. Steps of different
     *                                   interleaved instances are never constrained by each other.
     */
    public function __construct(array $journeys = [])
    {
        foreach ($journeys as $index => $journey) {
            $label = count($journeys) > 1 ? JourneyRunner::instanceLabel($index).': ' : '';
            $steps = [];

            foreach ($journey->steps() as $step) {
                $steps[$step->name()] = $step;
            }

            foreach ($steps as $name => $step) {
                $pending = $step->dependencies();
                $ancestors = [];

                while ($pending !== []) {
                    $dependency = array_pop($pending);

                    if (! isset($ancestors[$dependency]) && isset($steps[$dependency])) {
                        $ancestors[$dependency] = $dependency;
                        array_push($pending, ...$steps[$dependency]->dependencies());

                        // A repeatable dependency can run again after $name.
                        if (! $steps[$dependency]->isRepeatable()) {
                            $pair = $label.$name.self::PAIR_GLUE.$label.$dependency;
                            $this->impossible[$pair] = $pair;
                        }
                    }
                }
            }
        }
    }

    public function record(Trail $trail): void
    {
        $steps = $trail->steps();

        $this->trails++;
        $this->modes[$trail->mode()] = ($this->modes[$trail->mode()] ?? 0) + 1;

        $signature = implode(self::PAIR_GLUE, $steps);
        $this->orderings[$signature] = ($this->orderings[$signature] ?? 0) + 1;

        /** @var array<string, true> $seen */
        $seen = [];
        /** @var array<string, true> $pairs */
        $pairs = [];

        foreach ($steps as $index => $step) {
            $this->runs[$step] = ($this->runs[$step] ?? 0) + 1;
            $this->effective[$step] = ($this->effective[$step] ?? 0) + ($trail->tookEffect($index) ? 1 : 0);
            $this->sawIneffective = $this->sawIneffective || ! $trail->tookEffect($index);
            $reason = $trail->ineffectiveReason($index);

            if ($reason !== null && $reason !== '') {
                $this->noopReasons[$step][$reason] = $reason;
            }

            foreach (array_keys($seen) as $before) {
                if ($before !== $step) {
                    $pairs[$before.self::PAIR_GLUE.$step] = true;
                }
            }

            $seen[$step] = true;
        }

        foreach (array_keys($seen) as $step) {
            $this->appearances[$step] = ($this->appearances[$step] ?? 0) + 1;
        }

        foreach (array_keys($pairs) as $pair) {
            $this->pairs[$pair] = ($this->pairs[$pair] ?? 0) + 1;
        }
    }

    public function trails(): int
    {
        return $this->trails;
    }

    public function distinctOrderings(): int
    {
        return count($this->orderings);
    }

    /** @return array<string, int> step => total executions, in first-execution order (canonical runs first, so declared order) */
    public function stepRuns(): array
    {
        return $this->runs;
    }

    /** @return array<string, int> step => executions that had an effect (not noop(), and not every actor request rejected) */
    public function effectiveRuns(): array
    {
        return $this->effective;
    }

    /** In how many trails some run of $before preceded some run of $after. */
    public function timesBefore(string $before, string $after): int
    {
        return $this->pairs[$before.self::PAIR_GLUE.$after] ?? 0;
    }

    /**
     * Ordered pairs of distinct steps never observed in that order in any
     * trail, less the pairs after() makes impossible. Pairs closed off by
     * when() are expected here; an unconstrained pair on this list means the
     * shuffles never explored that ordering — the run needs more (or
     * different) seeds to claim it did.
     *
     * @return list<array{string, string}>
     */
    public function unseenPairs(): array
    {
        $unseen = [];
        $steps = array_keys($this->runs);

        foreach ($steps as $before) {
            foreach ($steps as $after) {
                $pair = $before.self::PAIR_GLUE.$after;

                if ($before !== $after && ! isset($this->pairs[$pair]) && ! isset($this->impossible[$pair])) {
                    $unseen[] = [$before, $after];
                }
            }
        }

        return $unseen;
    }

    public function describe(): string
    {
        if ($this->trails === 0) {
            return '  (no trails recorded)';
        }

        $modes = [];

        foreach (['canonical', 'shuffled', 'repeat-heavy', 'exhaustive'] as $mode) {
            if (isset($this->modes[$mode])) {
                $modes[] = sprintf('%d %s', $this->modes[$mode], $mode);
            }
        }

        $lines = [sprintf(
            '%d trails (%s), %d distinct orderings',
            $this->trails,
            implode(', ', $modes),
            count($this->orderings),
        )];

        if ($this->runs !== []) {
            $lines[] = 'Step executions:';
            $width = max(array_map(strlen(...), array_keys($this->runs)));

            foreach ($this->runs as $step => $count) {
                $lines[] = sprintf(
                    '  %s  %d runs in %d/%d trails%s%s',
                    str_pad($step, $width),
                    $count,
                    $this->appearances[$step] ?? 0,
                    $this->trails,
                    $this->sawIneffective ? sprintf(', %d effective', $this->effective[$step]) : '',
                    isset($this->noopReasons[$step]) ? sprintf(' (no effect: %s)', implode('; ', $this->noopReasons[$step])) : '',
                );
            }
        }

        $excluded = count(array_filter(
            $this->impossible,
            fn (string $pair): bool => array_diff(explode(self::PAIR_GLUE, $pair), array_keys($this->runs)) === [],
        ));
        $possible = count($this->runs) * (count($this->runs) - 1) - $excluded;
        $unseen = $this->unseenPairs();

        if ($unseen === []) {
            $lines[] = sprintf('All %d orderings of step pairs observed%s.', $possible, $excluded > 0 ? sprintf(' (%d excluded by after())', $excluded) : '');
        } else {
            $lines[] = sprintf('Orderings of step pairs never observed (%d of %d%s):', count($unseen), $possible, $excluded > 0 ? sprintf(', %d excluded by after()', $excluded) : '');

            foreach (array_slice($unseen, 0, 10) as [$before, $after]) {
                $lines[] = sprintf('  "%s" before "%s"', $before, $after);
            }

            if (count($unseen) > 10) {
                $lines[] = sprintf('  ... and %d more', count($unseen) - 10);
            }
        }

        return implode(PHP_EOL, $lines);
    }
}
