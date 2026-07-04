<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests;

use Orchestra\Testbench\TestCase as Testbench;

abstract class TestCase extends Testbench
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }
}
