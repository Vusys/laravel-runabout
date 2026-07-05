<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Support\Facades\DB;

/**
 * Fluent executor returned by RunsJourneys::journey(). Runs the canonical
 * order plus N seeded shuffles, each wrapped so state resets between trails.
 */
final class PendingJourney
{
    private int $shuffles = 10;

    private ?int $seed = null;

    /** @param Closure(Closure): void $wrapper Wraps each trail; the default (from RunsJourneys) rolls back a database transaction. */
    public function __construct(
        private readonly Journey $journey,
        private Closure $wrapper,
        private readonly ?HttpDriver $http = null,
    ) {}

    /** How many seeded shuffled trails to run after the canonical one. */
    public function shuffles(int $count): self
    {
        $this->shuffles = $count;

        return $this;
    }

    /** Replay a single shuffled trail with this exact seed (also settable via RUNABOUT_SEED). */
    public function seed(int $seed): self
    {
        $this->seed = $seed;

        return $this;
    }

    /**
     * Replace the trail wrapper entirely — for apps that need a bespoke reset
     * (multiple connections, external stores) instead of the transaction
     * default or table truncation.
     *
     * @param  Closure(Closure): void  $wrapper
     */
    public function resetWith(Closure $wrapper): self
    {
        $this->wrapper = $wrapper;

        return $this;
    }

    /**
     * Reset by truncating the given tables after each trail instead of
     * rolling back a transaction. Use when the code under test commits or
     * manages transactions itself.
     */
    public function resetByTruncating(string ...$tables): self
    {
        return $this->resetWith(function (Closure $trail) use ($tables): void {
            try {
                $trail();
            } finally {
                $connection = DB::connection();
                $connection->getSchemaBuilder()->disableForeignKeyConstraints();

                try {
                    foreach ($tables as $table) {
                        $connection->table($table)->truncate();
                    }
                } finally {
                    $connection->getSchemaBuilder()->enableForeignKeyConstraints();
                }
            }
        });
    }

    public function run(): void
    {
        $runner = new JourneyRunner;

        $replaySeed = $this->seed ?? $this->seedFromEnvironment();

        if ($replaySeed !== null) {
            $this->trail($runner, $replaySeed, shuffle: true);

            return;
        }

        $this->trail($runner, $this->deriveSeed(0), shuffle: false);

        for ($i = 1; $i <= $this->shuffles; $i++) {
            $this->trail($runner, $this->deriveSeed($i), shuffle: true);
        }
    }

    private function trail(JourneyRunner $runner, int $seed, bool $shuffle): void
    {
        ($this->wrapper)(function () use ($runner, $seed, $shuffle): void {
            $runner->run($this->journey, $seed, $shuffle, $this->http);
        });
    }

    /**
     * Deterministic by default: derived from the journey class and the trail
     * index, so CI is stable run to run. RUNABOUT_SEED overrides for replay.
     */
    private function deriveSeed(int $index): int
    {
        return crc32($this->journey::class.'#'.$index);
    }

    private function seedFromEnvironment(): ?int
    {
        $seed = getenv('RUNABOUT_SEED');

        return is_string($seed) && is_numeric($seed) ? (int) $seed : null;
    }
}
