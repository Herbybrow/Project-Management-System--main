<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    public function createApplication()
    {
        $app = parent::createApplication();
        $name = $app['config']['database.default'];
        $connection = $app['config']["database.connections.{$name}"];
        $safeSqlite = $connection['driver'] === 'sqlite' && $connection['database'] === ':memory:';
        $safePostgres = $connection['driver'] === 'pgsql'
            && $connection['host'] === '127.0.0.1'
            && (string) $connection['port'] === '25432'
            && $connection['database'] === 'pms_audit_test'
            && $connection['username'] === 'audit_runner';

        if (! $app->environment('testing') || (! $safeSqlite && ! $safePostgres) || ! empty($connection['url'])) {
            throw new \RuntimeException('Tests require in-memory SQLite or the isolated workspace PostgreSQL audit database.');
        }

        return $app;
    }
}
