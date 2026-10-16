<?php

declare(strict_types=1);

namespace Vusys\Runabout\Exceptions;

use RuntimeException;
use Throwable;
use Vusys\Runabout\Trail;

final class JourneyFailedException extends RuntimeException
{
    private Trail $trail;

    /** @param string $journeys The journey class, or "A=ClassA + B=ClassB" for interleaved runs. */
    public static function wrap(string $journeys, Trail $trail, Throwable $cause): self
    {
        $mode = $trail->mode() === 'canonical' ? 'canonical order' : $trail->mode();
        $at = $trail->steps() === []
            ? 'before any step ran'
            : sprintf('at step %d ("%s")', count($trail->steps()), $trail->steps()[count($trail->steps()) - 1]);

        $message = sprintf(
            "Journey %s failed (%s, seed %d) %s.\nTrail:\n%s\n%s",
            $journeys,
            $mode,
            $trail->seed(),
            $at,
            $trail->describe(),
            $cause->getMessage(),
        );

        // The canonical order reproduces by simply running again; a seeded
        // replay would run a shuffled trail instead and take a different path.
        // A replayed trail carries its own order, so it replays as an artifact.
        if ($trail->mode() === 'replayed') {
            $artifact = json_encode($trail->artifact());
            $message .= sprintf("\nReplay with RUNABOUT_TRAIL='%s'.", $artifact === false ? '{}' : $artifact);
        } elseif ($trail->mode() !== 'canonical') {
            $message .= sprintf("\nReplay with RUNABOUT_SEED=%d.", $trail->seed());
        }

        $exception = new self($message, 0, $cause);
        $exception->trail = $trail;

        return $exception;
    }

    /**
     * Re-frame a failure around its shrunk trail: the shrunk trail (and its
     * RUNABOUT_TRAIL replay line, carried in the replayed-mode message) becomes
     * the headline, with the original full trail one line away for anyone who
     * wants to see the whole thing.
     *
     * @param  self  $shrunkReplay  The replayed-mode failure of the shrunk trail.
     * @param  bool  $canonical  Whether the original trail was the canonical order, which a seed would not reproduce.
     */
    public static function shrunk(self $shrunkReplay, int $originalExecutions, int $originalSeed, int $replays, bool $canonical = false): self
    {
        $shrunkExecutions = count($shrunkReplay->trail()->tokens());
        $from = $canonical ? ' the canonical order' : '';

        // A shrink that removed no executions still minimised drawn values.
        $headline = $shrunkExecutions < $originalExecutions
            ? sprintf('Shrunk%s from %d executions to %d (%d replays):', $from, $originalExecutions, $shrunkExecutions, $replays)
            : sprintf('Shrunk drawn values%s; all %d executions are needed (%d replays):', $canonical ? ' in the canonical order' : '', $originalExecutions, $replays);

        $message = $headline."\n".$shrunkReplay->getMessage()
            .($canonical
                ? sprintf("\nRe-run the journey to reproduce the full %d-execution trail.", $originalExecutions)
                : sprintf("\nReplay the full %d-execution trail with RUNABOUT_SEED=%d.", $originalExecutions, $originalSeed));

        $exception = new self($message, 0, $shrunkReplay->getPrevious());
        $exception->trail = $shrunkReplay->trail();

        return $exception;
    }

    public function trail(): Trail
    {
        return $this->trail;
    }
}
