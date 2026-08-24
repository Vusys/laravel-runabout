<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Closure;
use Vusys\Runabout\Trail;
use Vusys\Runabout\TrailCoverage;

/**
 * The run's STDERR reporting: the per-trail verbose log (RUNABOUT_VERBOSE) and
 * the end-of-run coverage summary (RUNABOUT_COVERAGE). Both write to STDERR
 * rather than stdout, which the test runner swallows.
 */
final class TrailReporter
{
    /** A fresh collector when coverage is enabled, null when it is off. */
    public static function coverage(): ?TrailCoverage
    {
        return Environment::coverageEnabled() ? new TrailCoverage : null;
    }

    /**
     * A per-trail printer when verbose output is enabled, null when it is off.
     *
     * @param  string  $journeys  The run's journey names, for the log prefix.
     * @param  int|null  $total  Expected trail count, or null when it is not known up front (exhaustive mode).
     * @return Closure(Trail): void|null
     */
    public static function verbosePrinter(string $journeys, ?int $total): ?Closure
    {
        if (! Environment::verboseEnabled()) {
            return null;
        }

        $count = 0;

        return function (Trail $trail) use ($journeys, $total, &$count): void {
            $count++;

            fwrite(STDERR, sprintf(
                "\n[%s] trail %s (%s, seed %d)\n%s\n",
                $journeys,
                $total === null ? (string) $count : sprintf('%d/%d', $count, $total),
                $trail->mode(),
                $trail->seed(),
                $trail->describe(markLast: false),
            ));
        };
    }

    /** Print the aggregate coverage summary once the run has finished. */
    public static function printCoverage(string $journeys, TrailCoverage $coverage): void
    {
        fwrite(STDERR, sprintf("\n[%s] trail coverage\n%s\n", $journeys, $coverage->describe()));
    }
}
