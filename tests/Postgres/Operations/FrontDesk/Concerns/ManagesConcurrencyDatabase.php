<?php

namespace Tests\Postgres\Operations\FrontDesk\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Centralized helper for isolated concurrency proof tests that require
 * a temporary PostgreSQL database with a fresh migration.
 *
 * Replaces the fragile inline $this->artisan('migrate') pattern with
 * Artisan::call('migrate') for consistent behavior across invocation
 * methods (Bash, PowerShell, CI).
 */
trait ManagesConcurrencyDatabase
{
    protected string $concurrencyDb;

    /**
     * Create a temporary concurrency database, configure the pgsql_concurrency
     * connection, and run migrations via Artisan::call().
     *
     * @param  string       $prefix  DB name prefix (e.g. 'ivorq_concurrency_fd_b3_')
     * @param  string|null  $testNow Carbon test-now value (optional)
     * @return string  The generated temporary database name.
     */
    protected function setUpConcurrencyDatabase(string $prefix, ?string $testNow = null): string
    {
        $this->concurrencyDb = $prefix . Str::lower(Str::random(8));

        DB::statement("CREATE DATABASE \"{$this->concurrencyDb}\"");
        DB::disconnect();

        config(['database.connections.pgsql_concurrency' => [
            'driver'   => 'pgsql',
            'host'     => config('database.connections.pgsql.host'),
            'port'     => config('database.connections.pgsql.port'),
            'database' => $this->concurrencyDb,
            'username' => config('database.connections.pgsql.username'),
            'password' => config('database.connections.pgsql.password'),
        ]]);

        DB::purge('pgsql_concurrency');

        if ($testNow !== null) {
            Carbon::setTestNow(Carbon::parse($testNow));
        }

        Artisan::call('migrate', [
            '--database' => 'pgsql_concurrency',
            '--force'    => true,
        ]);

        return $this->concurrencyDb;
    }

    /**
     * Terminate backends on the temporary database and drop it.
     */
    protected function tearDownConcurrencyDatabase(): void
    {
        Carbon::setTestNow();

        DB::disconnect('pgsql_concurrency');

        DB::statement("SELECT pg_terminate_backend(pg_stat_activity.pid)
            FROM pg_stat_activity
            WHERE pg_stat_activity.datname = '{$this->concurrencyDb}'
              AND pid <> pg_backend_pid()");

        DB::statement("DROP DATABASE IF EXISTS \"{$this->concurrencyDb}\"");
    }
}
