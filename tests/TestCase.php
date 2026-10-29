<?php

namespace Tests;

use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use RuntimeException;

abstract class TestCase extends BaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(PreventRequestsDuringMaintenance::class);
    }

    public function createApplication()
    {
        $application = parent::createApplication();
        $connection = $application['config']->get('database.default');
        $database = $application['config']->get("database.connections.{$connection}.database");

        if (! $application->environment('testing')
            || ! (($connection === 'sqlite' && $database === ':memory:')
                || ($connection === 'mysql' && $database === 'consignment_testing'))) {
            throw new RuntimeException('Tests may only use SQLite :memory: or the dedicated consignment_testing MySQL database.');
        }

        return $application;
    }
}
