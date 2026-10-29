<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Modules\Finance\Payables\Services\SupplierInvoiceRegistrationService;
use Modules\Foundation\User\Models\User;

$argsFile = $argv[1] ?? '';
if ($argsFile === '' || ! is_file($argsFile)) {
    fwrite(STDERR, "Missing args file\n");
    exit(1);
}

$args = json_decode((string) file_get_contents($argsFile), true);
if (! is_array($args)) {
    fwrite(STDERR, "Invalid args JSON\n");
    exit(1);
}

$resultFile = (string) ($args['result_file'] ?? '');
$barrier = (string) ($args['barrier'] ?? '');
$runId = (string) ($args['run_id'] ?? '');
$workerId = (string) ($args['worker_id'] ?? '');
$propertyId = (string) ($args['property_id'] ?? '');
$actorId = (string) ($args['actor_id'] ?? '');
$purchaseOrderId = (string) ($args['purchase_order_id'] ?? '');
$goodsReceiptId = (string) ($args['goods_receipt_id'] ?? '');

if ($resultFile === '' || $barrier === '' || $runId === '' || $workerId === '') {
    fwrite(STDERR, "Missing worker arguments\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function finP01R1Signal(string $barrier, string $name, string $runId, array $payload = []): void
{
    file_put_contents($barrier.'-'.$name.'.json', json_encode($payload + [
        'run_id' => $runId,
        'name' => $name,
        'pid' => getmypid(),
        'at' => microtime(true),
    ]));
}

function finP01R1WaitFor(string $barrier, string $name, string $runId, int $timeoutMs = 10000): array
{
    $path = $barrier.'-'.$name.'.json';
    $deadline = microtime(true) + ($timeoutMs / 1000);

    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                return $payload;
            }
        }

        usleep(25000);
    }

    throw new RuntimeException("FIN_P01_R1_BARRIER_TIMEOUT:{$name}");
}

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $kernel = $app->make(Kernel::class);
    $kernel->bootstrap();

    $actor = User::whereKey($actorId)->where('is_active', true)->first();
    if (! $actor) {
        throw new RuntimeException('Actor unavailable.');
    }

    $pgPid = DB::selectOne('SELECT pg_backend_pid() as pid')->pid;
    finP01R1Signal($barrier, 'ready-'.$workerId, $runId, [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ]);
    finP01R1WaitFor($barrier, 'ready-w0', $runId);
    finP01R1WaitFor($barrier, 'ready-w1', $runId);

    try {
        $result = app(SupplierInvoiceRegistrationService::class)->registerFromSource([
            'property_id' => $propertyId,
            'purchase_order_id' => $purchaseOrderId,
            'goods_receipt_id' => $goodsReceiptId,
            'invoice_number' => 'SINV-CONC-'.strtoupper($workerId),
            'invoice_date' => '2026-10-29',
            'registration_idempotency_key' => 'fin-p01-r1-'.$workerId,
        ], $actor);

        $payload = [
            'status' => 'registered',
            'invoice_id' => $result['invoice']->id,
            'quantity' => (string) $result['invoice']->lines->first()->quantity,
        ];
    } catch (Throwable $exception) {
        $payload = [
            'status' => 'rejected',
            'error' => $exception->getMessage(),
        ];
    }

    file_put_contents($resultFile, json_encode($payload + [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ]));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'status' => 'harness_error',
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'error' => $exception->getMessage(),
        'file' => $exception->getFile().':'.$exception->getLine(),
    ]));
    exit(1);
}
