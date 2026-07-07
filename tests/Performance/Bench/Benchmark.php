<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Performance\Bench;

use Illuminate\Support\Facades\DB;

/**
 * Captures wall-clock time and query count around a single operation.
 *
 * Results print as test output and are converted to Bencher Metric
 * Format by .github/scripts/perf-to-bmf.php for continuous tracking.
 */
final readonly class Benchmark
{
    public function __construct(
        public string $label,
        public float $wallSeconds,
        public int $queries,
    ) {}

    /**
     * Run $operation, return a populated Benchmark.
     */
    public static function run(string $label, callable $operation): self
    {
        DB::flushQueryLog();
        DB::enableQueryLog();

        $start = microtime(true);

        try {
            $operation();
        } finally {
            $wall = microtime(true) - $start;
            DB::disableQueryLog();
        }

        return new self(
            label: $label,
            wallSeconds: $wall,
            queries: count(DB::getQueryLog()),
        );
    }

    public function toLine(): string
    {
        return sprintf(
            '%-60s  %7.3f ms  %5d queries',
            $this->label,
            $this->wallSeconds * 1000,
            $this->queries,
        );
    }
}
