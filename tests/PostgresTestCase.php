<?php

namespace Tests;

use RuntimeException;

abstract class PostgresTestCase extends TestCase
{
    public function createApplication()
    {
        $appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
        $dbConnection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
        $dbDatabase = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');

        if ($appEnv !== 'testing' || $dbConnection !== 'pgsql' || $dbDatabase !== 'ivorq_testing') {
            throw new RuntimeException('PG_TEST_GUARD_BLOCKED');
        }

        $app = parent::createApplication();

        $config = $app->make('config');

        if ($config->get('app.env') !== 'testing' ||
            $config->get('database.default') !== 'pgsql' ||
            $config->get('database.connections.pgsql.database') !== 'ivorq_testing') {
            throw new RuntimeException('PG_TEST_GUARD_BLOCKED');
        }

        return $app;
    }
}
