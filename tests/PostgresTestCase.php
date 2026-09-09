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
        $expectedDatabase = $this->expectedPostgresDatabase();

        if ($appEnv !== 'testing' || $dbConnection !== 'pgsql' || $dbDatabase !== $expectedDatabase) {
            throw new RuntimeException('PG_TEST_GUARD_BLOCKED');
        }

        $app = parent::createApplication();

        $config = $app->make('config');

        if ($config->get('app.env') !== 'testing' ||
            $config->get('database.default') !== 'pgsql' ||
            $config->get('database.connections.pgsql.database') !== $expectedDatabase) {
            throw new RuntimeException('PG_TEST_GUARD_BLOCKED');
        }

        return $app;
    }

    protected function expectedPostgresDatabase(): string
    {
        $override = $_SERVER['IVORQ_PG_TEST_DATABASE']
            ?? $_ENV['IVORQ_PG_TEST_DATABASE']
            ?? getenv('IVORQ_PG_TEST_DATABASE');

        if ($override === false || $override === null || $override === '') {
            return 'ivorq_testing';
        }

        if ($override !== 'ivorq_cc_p01f_adjustment_key_fix_20260907_8af9813b') {
            throw new RuntimeException('PG_TEST_GUARD_BLOCKED');
        }

        return $override;
    }
}
