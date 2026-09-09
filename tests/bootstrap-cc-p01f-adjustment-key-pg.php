<?php

declare(strict_types=1);

$appEnv = $_SERVER['APP_ENV'] ?? $_ENV['APP_ENV'] ?? getenv('APP_ENV');
$dbConnection = $_SERVER['DB_CONNECTION'] ?? $_ENV['DB_CONNECTION'] ?? getenv('DB_CONNECTION');
$dbDatabase = $_SERVER['DB_DATABASE'] ?? $_ENV['DB_DATABASE'] ?? getenv('DB_DATABASE');
$dbUsername = $_SERVER['DB_USERNAME'] ?? $_ENV['DB_USERNAME'] ?? getenv('DB_USERNAME');
$dbPassword = $_SERVER['DB_PASSWORD'] ?? $_ENV['DB_PASSWORD'] ?? getenv('DB_PASSWORD');

if ($appEnv !== 'testing'
    || $dbConnection !== 'pgsql'
    || $dbDatabase !== 'ivorq_cc_p01f_adjustment_key_fix_20260907_8af9813b'
    || empty($dbUsername)
    || empty($dbPassword)
    || file_exists(__DIR__.'/../bootstrap/cache/config.php')) {
    fwrite(STDERR, "CC_P01F_ADJUSTMENT_KEY_PG_TEST_GUARD_BLOCKED\n");
    exit(1);
}

require __DIR__.'/../vendor/autoload.php';
