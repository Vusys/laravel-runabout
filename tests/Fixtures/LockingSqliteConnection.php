<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests\Fixtures;

use Illuminate\Database\SQLiteConnection;

/**
 * SQLite under a MySQL driver name, so racing() interferes as it would on a
 * driver that writes lock clauses. Its grammar still drops them, so only a
 * locked read written out by hand is seen as locked.
 */
final class LockingSqliteConnection extends SQLiteConnection
{
    #[\Override]
    public function getDriverName(): string
    {
        return 'mysql';
    }
}
