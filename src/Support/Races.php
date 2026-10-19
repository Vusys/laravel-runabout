<?php

declare(strict_types=1);

namespace Vusys\Runabout\Support;

use Closure;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use WeakMap;

/**
 * Context::racing()'s interference: while a request runs, the first unlocked
 * read of a table the seeded draws pick is followed, before the app can act on
 * it, by a competitor, as if a parallel request landed in the check-then-act
 * window. Once the request locks a row of the table inside a transaction, a
 * real competitor would wait for that transaction to end, so there is no
 * interference until it does. Query listeners can't be removed, so one is
 * registered per events dispatcher and dispatches to the race armed at the time.
 *
 * @internal
 */
final class Races
{
    /** Drivers whose grammar writes lock clauses into the SQL, so a locked read can be told from an unlocked one. */
    private const array LOCKING_DRIVERS = ['mysql', 'mariadb', 'pgsql'];

    /** @var WeakMap<Dispatcher, true>|null */
    private static ?WeakMap $listening = null;

    /** @var array{table: string, competitor: Closure(): mixed, interfereHere: Closure(): bool, unsupported: string|null, levels: array<string, int>, lockedAt: int|null}|null */
    private static ?array $armed = null;

    private static bool $skipReported = false;

    /** Start a run: a driver that can't be raced is reported once per run. */
    public static function beginRun(): void
    {
        self::$skipReported = false;
    }

    /**
     * @template T
     *
     * @param  Closure(): mixed  $competitor
     * @param  Closure(): T  $request
     * @param  Closure(): bool  $interfereHere  Asked at each unlocked read of $table: whether to interfere at this one.
     * @return T
     */
    public static function race(string $table, Closure $competitor, Closure $request, Closure $interfereHere): mixed
    {
        if (self::$armed !== null) {
            throw new RuntimeException('racing() can\'t be nested: a step races one request against one competitor at a time.');
        }

        self::listen();
        // A lock taken outside a transaction the request opened (at the
        // trail's own level) is released at once in production.
        $levels = array_map(fn ($connection): int => $connection->transactionLevel(), DB::getConnections());
        self::$armed = ['table' => $table, 'competitor' => $competitor, 'interfereHere' => $interfereHere, 'unsupported' => null, 'levels' => $levels, 'lockedAt' => null];

        try {
            return $request();
        } finally {
            $unsupported = self::$armed['unsupported'] ?? null;
            self::$armed = null;

            if ($unsupported !== null && ! self::$skipReported) {
                self::$skipReported = true;
                TrailReporter::write(sprintf(
                    "\n[runabout] racing() ran its request on \"%s\" without interference: Laravel's %s grammar drops lock clauses, so an unlocked read can't be told from a locked one. Run race journeys on MySQL, MariaDB or Postgres.\n",
                    $table,
                    $unsupported,
                ));
            }
        }
    }

    /** Whether $sql reads $table without a row lock (FOR UPDATE, FOR SHARE, LOCK IN SHARE MODE). */
    public static function isUnlockedRead(string $sql, string $table): bool
    {
        return self::reads($sql, $table) && ! self::locks($sql);
    }

    private static function reads(string $sql, string $table): bool
    {
        $quoted = preg_quote($table, '/');

        return preg_match('/^\s*\(?\s*select\b/i', $sql) === 1
            && preg_match('/\b(from|join)\s+[`"\[]?'.$quoted.'[`"\]]?(\s|,|\)|$)/i', $sql) === 1;
    }

    private static function locks(string $sql): bool
    {
        return preg_match('/\bfor\s+(update|share|no\s+key\s+update|key\s+share)\b|\block\s+in\s+share\s+mode\b/i', $sql) === 1;
    }

    private static function listen(): void
    {
        $events = resolve('events');
        self::$listening ??= new WeakMap;

        if (self::$listening[$events] ?? false) {
            return;
        }

        self::$listening[$events] = true;
        $events->listen(QueryExecuted::class, self::queried(...));
    }

    private static function queried(QueryExecuted $query): void
    {
        $race = self::$armed;

        if ($race === null || ! self::reads($query->sql, $race['table'])) {
            return;
        }

        $level = $query->connection->transactionLevel();

        if (self::locks($query->sql)) {
            if ($level > ($race['levels'][$query->connection->getName()] ?? 0) && $race['lockedAt'] === null) {
                $race['lockedAt'] = $level;
                self::$armed = $race;
            }

            return;
        }

        if ($race['lockedAt'] !== null) {
            if ($level >= $race['lockedAt']) {
                return;
            }

            // The transaction holding the lock has ended.
            $race['lockedAt'] = null;
            self::$armed = $race;
        }

        $driver = $query->connection->getDriverName();

        if (! in_array($driver, self::LOCKING_DRIVERS, true)) {
            $race['unsupported'] = $driver;
            self::$armed = $race;

            return;
        }

        if (! ($race['interfereHere'])()) {
            return;
        }

        // At most once, and the competitor's own reads don't count.
        self::$armed = null;
        ($race['competitor'])();
    }
}
