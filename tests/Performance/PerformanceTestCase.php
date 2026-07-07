<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Performance;

use Vusys\Runabout\Tests\Performance\Bench\Benchmark;
use Vusys\Runabout\Tests\TestCase;

/**
 * Base class for performance benchmarks.
 *
 * Adds a `bench()` helper for clean wall-clock + query-count capture,
 * a scale progression knob, and stderr heartbeats that the Bencher
 * workflow parses (via .github/scripts/perf-to-bmf.php).
 *
 * The Performance suite is excluded from the default `phpunit` run
 * (see phpunit.xml) — it runs only under `--testsuite Performance`.
 */
abstract class PerformanceTestCase extends TestCase
{
    /** @var list<Benchmark> */
    private array $benchmarks = [];

    /**
     * Read the desired top scale from the PERF_SCALE_MAX env var.
     * Here "scale" is a shuffle count. Defaults to 100 — large enough
     * to be informative, small enough to run in CI in a minute or two.
     */
    protected function maxScale(): int
    {
        $env = getenv('PERF_SCALE_MAX');

        if ($env === false || $env === '' || ! is_numeric($env)) {
            return 100;
        }

        return max(10, (int) $env);
    }

    /**
     * Returns the shuffle counts the current benchmark should exercise,
     * capped at {@see maxScale()}. Default progression is 10 → 50 → 100
     * → 250 → 500; benchmarks iterate this list and skip larger entries
     * when the harness is running with a lower cap.
     *
     * @return list<int>
     */
    protected function scales(): array
    {
        $all = [10, 50, 100, 250, 500];
        $cap = $this->maxScale();

        return array_values(array_filter($all, static fn (int $n): bool => $n <= $cap));
    }

    protected function bench(string $label, callable $operation): Benchmark
    {
        // Heartbeat to stderr — bypasses `--testdox`'s per-class
        // buffering, so the live CI log shows "starting X" before the
        // bench runs. When a benchmark hangs, the last heartbeat tells
        // us exactly which one wedged. The Bencher converter reads the
        // `::bench-end::` lines from this stream.
        fwrite(STDERR, "  ::bench-start:: {$label}\n");
        @fflush(STDERR);

        $result = Benchmark::run($label, $operation);
        $this->benchmarks[] = $result;

        fwrite(STDERR, "  ::bench-end::   {$label}  ".$result->toLine()."\n");
        @fflush(STDERR);

        return $result;
    }

    /**
     * Benchmarks don't assert thresholds — they print results and feed
     * Bencher. Tests still need an assertion to avoid PHPUnit's "risky
     * test — no assertions" warning; call this at the end of a bench
     * loop to assert that at least one benchmark ran.
     */
    protected function assertBenchmarksRan(): void
    {
        $this->assertGreaterThan(0, count($this->benchmarks), 'expected at least one benchmark to have run');
    }

    protected function tearDown(): void
    {
        if ($this->benchmarks !== []) {
            $driver = getenv('DB_CONNECTION') ?: 'sqlite';

            fwrite(STDOUT, "\n[".strtoupper($driver).'] '.static::class."\n");
            foreach ($this->benchmarks as $b) {
                fwrite(STDOUT, '  '.$b->toLine()."\n");
            }
        }

        parent::tearDown();
    }
}
