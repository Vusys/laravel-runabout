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
    }

    /**
     * Attach the draws (and value-opacity) an execution made to its token —
     * called after the execution so the value shrinker has its baseline.
     *
     * @param  list<Draw>  $draws
     */
    public function attachDraws(array $draws, bool $opaque): void
    {
        $last = count($this->tokens) - 1;

        if ($last >= 0) {
            $this->draws[$last] = $draws;
            $this->opaque[$last] = $opaque;
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
     * ready to JSON-encode for RUNABOUT_TRAIL.
     *
     * @return array{seed: int, steps: list<array{0: string|null, 1: string, 2: int}>}
     */
    public function artifact(): array
    {
        return [
            'seed' => $this->seed,
            'steps' => array_map(fn (TrailToken $token): array => $token->toArray(), $this->tokens),
        ];
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
            $lines[] = sprintf('%s %2d. %s', $marker, $index + 1, $label);
        }

        return implode(PHP_EOL, $lines);
    }
}
