<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $test = new \Tests\Feature\Finance\Banking\ReconciliationSessionModuleTest('test_session_is_isolated_by_property');
    $test->setUp();
    $test->test_session_is_isolated_by_property();
    $test->tearDown();
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
