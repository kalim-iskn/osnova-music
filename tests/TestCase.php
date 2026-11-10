<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function setUpTraits()
    {
        if (config('database.default') !== 'sqlite'
            || config('database.connections.sqlite.database') !== ':memory:') {
            throw new \RuntimeException('Tests require an isolated in-memory SQLite database.');
        }

        return parent::setUpTraits();
    }

    protected function setUp(): void
    {
        $_SERVER['DB_CONNECTION'] = $_ENV['DB_CONNECTION'] = 'sqlite';
        $_SERVER['DB_DATABASE'] = $_ENV['DB_DATABASE'] = ':memory:';
        putenv('DB_CONNECTION=sqlite');
        putenv('DB_DATABASE=:memory:');

        parent::setUp();

        $this->withoutVite();
    }
}
