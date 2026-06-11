<?php
require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

try {
    $suite = new PHPUnit\Framework\TestSuite();
    $suite->addTestFile('tests/Feature/Finance/Banking/ReconciliationSessionModuleTest.php');
    $runner = new PHPUnit\TextUI\TestRunner();
    $runner->run($suite);
} catch (\Throwable $e) {
    echo $e->getMessage() . "\n";
    echo $e->getTraceAsString() . "\n";
}
