<?php

declare(strict_types=1);

namespace Vusys\Runabout\Tests;

use Illuminate\Routing\Router;
use Orchestra\Testbench\TestCase as Testbench;
use Vusys\Runabout\Tests\Fixtures\Http\ForumController;

abstract class TestCase extends Testbench
{
    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/Fixtures/Migrations');
    }

    /** @param Router $router */
    protected function defineRoutes($router): void
    {
        $router->post('/communities', [ForumController::class, 'createCommunity']);
        $router->post('/communities/{community}/posts', [ForumController::class, 'draftPost']);
        $router->post('/posts/{post}/publish', [ForumController::class, 'publish']);
        $router->post('/posts/{post}/vote', [ForumController::class, 'vote']);
        $router->post('/posts/{post}/lock', [ForumController::class, 'lock']);
    }
}
