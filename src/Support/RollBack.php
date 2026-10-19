<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Closure;
use Illuminate\Database\Connection;
use RuntimeException;
use Throwable;

/**
 * Run a trail, then roll back the transactions opened for it. A rollback that
 * fails never masks the trail's own failure; on its own it fails with an
 * explanation instead of a bare driver error.
 *
 * @internal
 */
final class RollBack
{
    /**
     * @param  Closure(): void  $trail
     * @param  list<Connection>  $connections  Rolled back in the order given.
     */
    public static function after(Closure $trail, array $connections): void
    {
        $failure = null;

        try {
            $trail();
        } catch (Throwable $caught) {
            $failure = $caught;
        }

        foreach ($connections as $connection) {
            try {
                $connection->rollBack();
            } catch (Throwable $rollback) {
                $failure ??= new RuntimeException(sprintf(
                    'Rolling back the trail\'s transaction on connection "%s" failed: %s Something in the trail ended the transaction (an implicit commit, usually a schema change on MySQL or MariaDB), so its rows were not rolled back. Use resetByTruncating(...) for journeys that run schema changes.',
                    $connection->getName(),
                    $rollback->getMessage(),
                ), 0, $rollback);
            }
        }

        if ($failure instanceof Throwable) {
            throw $failure;
        }
    }
}
