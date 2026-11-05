<?php

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Enums\GoodsReceiptExceptionResolutionTypeEnum;
use Modules\Operations\Inventory\Models\GoodsReceiptLineException;
use Modules\Operations\Inventory\Services\ControlledGoodsReceiptExceptionService;
use Modules\Operations\Purchasing\Services\PurchaseOrderService;
use Modules\Operations\Purchasing\Services\PurchaseRequestService;
use Modules\Operations\Purchasing\Services\PurchasingDocumentNumberAllocator;
use Shared\Exceptions\BusinessLogicException;
use Shared\Services\CurrentPropertyService;

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

if ($resultFile === '' || $barrier === '' || $runId === '' || $workerId === '') {
    fwrite(STDERR, "Missing worker arguments\n");
    exit(1);
}

putenv('APP_ENV=testing');
$_ENV['APP_ENV'] = 'testing';

function procurementSignal(string $barrier, string $name, string $runId): void
{
    file_put_contents($barrier.'-'.$name.'.json', json_encode([
        'run_id' => $runId,
        'name' => $name,
        'pid' => getmypid(),
    ], JSON_THROW_ON_ERROR));
}

function procurementWaitFor(string $barrier, string $name, string $runId): void
{
    $path = $barrier.'-'.$name.'.json';
    $deadline = microtime(true) + 10;

    while (microtime(true) < $deadline) {
        if (is_file($path)) {
            $payload = json_decode((string) file_get_contents($path), true);
            if (is_array($payload) && ($payload['run_id'] ?? null) === $runId) {
                return;
            }
        }

        usleep(25000);
    }

    throw new RuntimeException("PROC_P01_CONCURRENCY_BARRIER_TIMEOUT:{$name}");
}

$pgPid = null;

try {
    require __DIR__.'/../../../../../vendor/autoload.php';
    $app = require __DIR__.'/../../../../../bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    $pgPid = (int) DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $propertyId = (string) ($args['property_id'] ?? '');
    app(CurrentPropertyService::class)->setPropertyId($propertyId);
    setPermissionsTeamId($propertyId);

    if (! empty($args['actor_id'])) {
        Auth::login(User::query()->findOrFail((string) $args['actor_id']));
    }

    procurementSignal($barrier, 'ready-'.$workerId, $runId);
    procurementWaitFor($barrier, 'ready-w0', $runId);
    procurementWaitFor($barrier, 'ready-w1', $runId);

    $mode = (string) ($args['mode'] ?? '');
    if ($mode === 'number') {
        $result = [
            'status' => 'allocated',
            'number' => app(PurchasingDocumentNumberAllocator::class)->allocate(
                $propertyId,
                (string) $args['document_type'],
                (int) $args['year'],
            ),
        ];
    } elseif ($mode === 'create_request') {
        $request = app(PurchaseRequestService::class)->createWithLines([
            'department_id' => (string) $args['department_id'],
            'required_date' => (string) $args['required_date'],
            'idempotency_key' => (string) $args['idempotency_key'],
        ], [[
            'inventory_item_id' => (string) $args['inventory_item_id'],
            'unit_id' => (string) $args['unit_id'],
            'quantity' => '1',
            'estimated_unit_cost' => '0',
        ]], User::query()->findOrFail((string) $args['actor_id']));
        $result = ['status' => 'created', 'purchase_request_id' => $request->id, 'request_no' => $request->request_no];
    } elseif ($mode === 'convert') {
        $order = app(PurchaseOrderService::class)->createFromApprovedPR(
            (string) $args['purchase_request_id'],
            (string) $args['vendor_id'],
            ['expected_delivery_date' => (string) $args['expected_delivery_date']],
            User::query()->findOrFail((string) $args['actor_id']),
        );
        $result = ['status' => 'converted', 'purchase_order_id' => $order->id, 'po_no' => $order->po_no];
    } elseif ($mode === 'decision') {
        $service = app(PurchaseRequestService::class);
        $actor = User::query()->findOrFail((string) $args['actor_id']);
        $request = $args['decision'] === 'approve'
            ? $service->approve((string) $args['purchase_request_id'], $actor, 'Concurrent approval')
            : $service->reject((string) $args['purchase_request_id'], $actor, 'Concurrent rejection');
        $result = ['status' => 'decided', 'decision' => $args['decision'], 'request_status' => $request->status->value];
    } elseif ($mode === 'revise_request') {
        $request = app(PurchaseRequestService::class)->revise(
            (string) $args['purchase_request_id'],
            User::query()->findOrFail((string) $args['actor_id']),
        );
        $result = [
            'status' => 'revised',
            'purchase_request_id' => $request->id,
            'revision_number' => $request->revision_number,
        ];
    } elseif ($mode === 'revise_order') {
        $order = app(PurchaseOrderService::class)->revise(
            (string) $args['purchase_order_id'],
            User::query()->findOrFail((string) $args['actor_id']),
        );
        $result = [
            'status' => 'revised',
            'purchase_order_id' => $order->id,
            'revision_number' => $order->revision_number,
        ];
    } elseif ($mode === 'resolve_receipt_exception') {
        $actor = User::query()->findOrFail((string) $args['actor_id']);
        $resolution = GoodsReceiptExceptionResolutionTypeEnum::from((string) $args['resolution']);
        $resolved = app(ControlledGoodsReceiptExceptionService::class)->resolve(
            GoodsReceiptLineException::withoutGlobalScope('property')->findOrFail((string) $args['exception_id']),
            $resolution,
            isset($args['accepted_quantity']) ? (string) $args['accepted_quantity'] : null,
            'Concurrent '.$resolution->value.' proof',
            $actor,
        );
        $result = [
            'status' => 'resolved',
            'actor_id' => $actor->id,
            'resolution' => $resolved->resolution_type->value,
            'exception_status' => $resolved->status->value,
        ];
    } else {
        throw new RuntimeException('Unknown concurrency worker mode.');
    }

    file_put_contents($resultFile, json_encode($result + [
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
    ], JSON_THROW_ON_ERROR));
} catch (BusinessLogicException $exception) {
    file_put_contents($resultFile, json_encode([
        'status' => 'business_error',
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
} catch (DomainException $exception) {
    file_put_contents($resultFile, json_encode([
        'status' => 'business_error',
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
        'error' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
} catch (Throwable $exception) {
    file_put_contents($resultFile, json_encode([
        'status' => 'harness_error',
        'worker_id' => $workerId,
        'php_pid' => getmypid(),
        'pg_backend_pid' => $pgPid,
        'error' => $exception->getMessage(),
        'file' => $exception->getFile().':'.$exception->getLine(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
