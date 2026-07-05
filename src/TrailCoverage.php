<?php

declare(strict_types=1);

namespace Vusys\Runabout;

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

    /** @var array<string, int> step => trails the step appeared in */
    private array $appearances = [];

    /** @var array<string, int> "before\x00after" => trails where some run of before preceded some run of after */
    private array $pairs = [];

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

        foreach ($steps as $step) {
            $this->runs[$step] = ($this->runs[$step] ?? 0) + 1;

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

    /** In how many trails some run of $before preceded some run of $after. */
    public function timesBefore(string $before, string $after): int
    {
        return $this->pairs[$before.self::PAIR_GLUE.$after] ?? 0;
    }

    /**
     * Ordered pairs of distinct steps never observed in that order in any
     * trail. Pairs constrained by after()/when() are expected here; an
     * unconstrained pair on this list means the shuffles never explored that
     * ordering — the run needs more (or different) seeds to claim it did.
     *
     * @return list<array{string, string}>
     */
    public function unseenPairs(): array
    {
        $unseen = [];
        $steps = array_keys($this->runs);

        foreach ($steps as $before) {
            foreach ($steps as $after) {
                if ($before !== $after && ! isset($this->pairs[$before.self::PAIR_GLUE.$after])) {
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
                    '  %s  %d runs in %d/%d trails',
                    str_pad($step, $width),
                    $count,
                    $this->appearances[$step] ?? 0,
                    $this->trails,
                );
            }
        }

        $possible = count($this->runs) * (count($this->runs) - 1);
        $unseen = $this->unseenPairs();

        if ($unseen === []) {
            $lines[] = sprintf('All %d orderings of step pairs observed.', $possible);
        } else {
            $lines[] = sprintf('Orderings of step pairs never observed (%d of %d):', count($unseen), $possible);

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
