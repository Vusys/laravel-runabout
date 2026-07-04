<?php

declare(strict_types=1);

namespace Vusys\Runabout;

use Closure;
use Illuminate\Support\Facades\DB;

/** Mix into a Laravel/Testbench TestCase to run journeys inside tests. */
trait RunsJourneys
{
    /** @param class-string<Journey>|Journey $journey */
    protected function journey(string|Journey $journey): PendingJourney
    {
        $journey = is_string($journey) ? new $journey() : $journey;

        return new PendingJourney($journey, $this->wrapTrail(...));
    }

    /**
     * Each trail runs against fresh database state: by default a transaction
     * on the default connection, rolled back afterwards. Override for apps
     * that need truncation or multiple connections.
     */
    protected function wrapTrail(Closure $trail): void
    {
        $connection = DB::connection();
        $connection->beginTransaction();

        try {
            $trail();
        } finally {
            $connection->rollBack();
        }
    }
}
