<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /**
     * Force the test database BEFORE the application boots.
     *
     * This stack runs inside Docker Compose, which injects real
     * DB_CONNECTION/DB_DATABASE env vars (pgsql) into the process. PHPUnit's
     * phpunit.xml <env> overrides only call putenv(), but Laravel's env()
     * helper checks $_ENV/$_SERVER first — so the container values would win
     * and RefreshDatabase would wipe the live database. Overriding every
     * source here guarantees tests always run on an in-memory SQLite DB.
     */
    protected function setUp(): void
    {
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');
        putenv('DB_URL=');
        $_ENV['DB_CONNECTION'] = 'sqlite';
        $_ENV['DB_DATABASE'] = ':memory:';
        $_SERVER['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = ':memory:';

        parent::setUp();
    }
}
