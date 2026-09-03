<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Modules\Finance\CostControl\Repositories\CostDeliveryModeOwnershipRepository;
use Modules\Finance\CostControl\Services\CostDeliveryCutoverService;
use Modules\Finance\CostControl\ValueObjects\CostDeliveryCutoverRequest;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;
use Modules\Operations\Inventory\Contracts\SynchronousCostValuationPort;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Services\InventoryPostingControlCoordinator;
use Modules\Operations\Inventory\ValueObjects\InventoryLedgerPostingIntent;

$config = json_decode((string) file_get_contents($argv[1]), true);
require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
DB::purge('pgsql');
DB::reconnect('pgsql');

$result = ['error' => null, 'transaction_id' => null, 'ledger_id' => null, 'cutover_id' => null];
try {
    touch($config['ready_file']);
    for ($attempt = 0; $attempt < 12000 && ! is_file($config['start_file']); $attempt++) {
        usleep(10000);
    }

    if ($config['action'] === 'activate_and_hold') {
        DB::transaction(function () use ($config, &$result): void {
            $request = new CostDeliveryCutoverRequest(...$config['request']);
            $cutover = app(CostDeliveryCutoverService::class)->activateGroup($request);
            $result['cutover_id'] = $cutover->id;
            touch($config['locked_file']);
            usleep(600000);
        });
    } elseif ($config['action'] === 'post_synchronous_and_hold') {
        DB::transaction(function () use ($config, &$result): void {
            $intent = makeIntent($config['intent']);
            $decision = app(CostDeliveryModePort::class)->resolveForPosting(
                $intent->propertyId,
                $intent->itemId,
                $intent->locationId,
            );
            touch($config['locked_file']);
            usleep(600000);
            $source = app(InventoryPostingControlCoordinator::class)->post(
                $intent,
                $config['actor_id'],
                $decision,
            );
            $result['transaction_id'] = $source->id;
            $result['ledger_id'] = app(SynchronousCostValuationPort::class)->applyReceipt($source->id);
        });
    } elseif ($config['action'] === 'apply_synchronous_and_hold') {
        DB::transaction(function () use ($config, &$result): void {
            $ownership = app(CostDeliveryModeOwnershipRepository::class)->findForUpdateByPropertyItem(
                $config['property_id'],
                $config['item_id'],
            );
            if ($ownership === null || $ownership->delivery_mode !== CostDeliveryMode::Synchronous) {
                throw new RuntimeException('P01F_WORKER_SYNCHRONOUS_OWNERSHIP_MISSING');
            }
            touch($config['locked_file']);
            usleep(600000);
            $result['ledger_id'] = app(SynchronousCostValuationPort::class)->applyReceipt(
                $config['source_inventory_transaction_id'],
            );
        });
    } else {
        throw new RuntimeException('P01F_WORKER_ACTION_INVALID');
    }
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.$exception->getMessage();
}
file_put_contents($config['result_file'], json_encode($result), LOCK_EX);

function makeIntent(array $intent): InventoryLedgerPostingIntent
{
    return new InventoryLedgerPostingIntent(
        propertyId: $intent['propertyId'],
        itemId: $intent['itemId'],
        locationId: $intent['locationId'],
        businessDate: $intent['businessDate'],
        occurredAt: Carbon::parse($intent['occurredAt']),
        sourceDocumentType: $intent['sourceDocumentType'],
        sourceDocumentId: $intent['sourceDocumentId'],
        sourceLineType: $intent['sourceLineType'],
        sourceLineId: $intent['sourceLineId'],
        movementRole: $intent['movementRole'],
        idempotencyKey: $intent['idempotencyKey'],
        transactionType: TransactionTypeEnum::from($intent['transactionType']),
        quantityChange: $intent['quantityChange'],
        unitCost: $intent['unitCost'],
        totalCost: $intent['totalCost'],
        reference: $intent['reference'] ?? null,
        notes: $intent['notes'] ?? null,
    );
}
