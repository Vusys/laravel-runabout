<?php

declare(strict_types=1);

namespace Vusys\Runabout;

/** The concrete ordered sequence of steps one execution took. */
final class Trail
{
    /** @var list<TrailToken> */
    private array $tokens = [];

    /** @var array<int, list<Draw>> Recorded draws, keyed by token index. */
    private array $draws = [];

    /** @var array<int, bool> Whether each token's execution touched the raw randomizer, keyed by token index. */
    private array $opaque = [];

    /** @var array<int, bool> Whether each token's draws were value-forced (pinned), keyed by token index. */
    private array $pinned = [];

    /** @param 'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'|'replayed' $mode */
    public function __construct(
        private readonly int $seed,
        private readonly string $mode,
    ) {}

    /** Record one execution. The run index is the stream key, not a positional recount. */
    public function record(?string $label, string $step, int $run): void
    {
        $this->tokens[] = new TrailToken($label, $step, $run);
        $this->draws[] = [];
        $this->opaque[] = false;
        $this->pinned[] = false;
    }

    /**
     * Attach the draws (and value-opacity) an execution made to its token —
     * called after the execution so the value shrinker has its baseline. A
     * $forced execution had its draws pinned by value shrinking, so those
     * values are written into the replay artifact.
     *
     * @param  list<Draw>  $draws
     */
    public function attachDraws(array $draws, bool $opaque, bool $forced = false): void
    {
        $last = count($this->tokens) - 1;

        if ($last >= 0) {
            $this->draws[$last] = $draws;
            $this->opaque[$last] = $opaque;
            $this->pinned[$last] = $forced;
        }
    }

    /** @return list<Draw> The draws recorded for the token at $index. */
    public function drawsAt(int $index): array
    {
        return $this->draws[$index] ?? [];
    }

    /** Whether the token at $index touched the raw randomizer (so value shrinking must skip it). */
    public function isOpaqueAt(int $index): bool
    {
        return $this->opaque[$index] ?? false;
    }

    public function seed(): int
    {
        return $this->seed;
    }

    /** @return 'canonical'|'shuffled'|'repeat-heavy'|'exhaustive'|'replayed' */
    public function mode(): string
    {
        return $this->mode;
    }

    public function isShuffled(): bool
    {
        return $this->mode !== 'canonical';
    }

    /** @return list<string> The labelled step names, in order — the human-facing view of the trail. */
    public function steps(): array
    {
        return array_map(fn (TrailToken $token): string => $token->labelled(), $this->tokens);
    }

    /** @return list<TrailToken> The execution tokens, in order — the replayable view of the trail. */
    public function tokens(): array
    {
        return $this->tokens;
    }

    /**
     * The compact replay artifact: the trail seed plus the ordered token list,
     * ready to JSON-encode for RUNABOUT_TRAIL. A token whose index is pinned
     * (by value shrinking) grows a fourth element — its forced draw values — so
     * the replay reproduces those exact values; unpinned tokens stay a plain
     * [label, step, run] triple and draw from the stream, keeping every
     * existing artifact valid.
     *
     * @return array{seed: int, steps: list<array{0: string|null, 1: string, 2: int, 3?: list<int>}>}
     */
    public function artifact(): array
    {
        $steps = [];

        foreach ($this->tokens as $index => $token) {
            if (($this->pinned[$index] ?? false) && ($this->draws[$index] ?? []) !== []) {
                $steps[] = [$token->label, $token->step, $token->run, array_map(fn (Draw $draw): int => $draw->value, $this->draws[$index])];
            } else {
                $steps[] = [$token->label, $token->step, $token->run];
            }
        }

        return ['seed' => $this->seed, 'steps' => $steps];
    }

    /** @param bool $markLast Point at the last step with a ">" marker — where the failure output points at the failing step. */
    public function describe(bool $markLast = true): string
    {
        if ($this->tokens === []) {
            return '  (no steps ran)';
        }

        $lines = [];
        $runs = [];
        $last = count($this->tokens) - 1;

        foreach ($this->tokens as $index => $token) {
            $name = $token->labelled();
            $runs[$name] = ($runs[$name] ?? 0) + 1;
            $marker = $markLast && $index === $last ? '>' : ' ';
            $label = $runs[$name] > 1 ? sprintf('%s (run %d)', $name, $runs[$name]) : $name;

            // Value-shrunk executions show the minimal drawn values, so the
            // trail reads like a hand-written test instead of a seed to re-derive.
            if (($this->pinned[$index] ?? false) && ($this->draws[$index] ?? []) !== []) {
                $label .= sprintf(' [drew %s]', implode(', ', array_map(fn (Draw $draw): string => (string) $draw->value, $this->draws[$index])));
            }

            $lines[] = sprintf('%s %2d. %s', $marker, $index + 1, $label);
        }

        return implode(PHP_EOL, $lines);
    }
}
