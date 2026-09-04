<?php

declare(strict_types=1);

use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Operations\Inventory\Contracts\CostDeliveryModePort;

$config = json_decode((string) file_get_contents($argv[1]), true);
require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
DB::purge('pgsql');
DB::reconnect('pgsql');

$result = ['error' => null];
try {
    touch($config['ready_file']);
    for ($attempt = 0; $attempt < 12000 && ! is_file($config['start_file']); $attempt++) {
        usleep(10000);
    }
    DB::transaction(function () use ($config): void {
        app(CostDeliveryModePort::class)->lockForDocumentMutation($config['property_id'], $config['item_id']);
        touch($config['locked_file']);
        usleep(600000);
        $receiptId = (string) Str::ulid();
        DB::table('inventory_receipts')->insert([
            'id' => $receiptId, 'property_id' => $config['property_id'],
            'receipt_number' => 'P01F-RACE-'.Str::random(8), 'status' => 'draft',
            'created_by' => $config['actor_id'], 'created_at' => now(), 'updated_at' => now(),
        ]);
        DB::table('inventory_receipt_lines')->insert([
            'id' => (string) Str::ulid(), 'property_id' => $config['property_id'],
            'receipt_id' => $receiptId, 'item_id' => $config['item_id'],
            'location_id' => $config['location_id'], 'quantity' => '1.0000',
            'unit_cost' => '1.00', 'line_total' => '1.00',
            'created_at' => now(), 'updated_at' => now(),
        ]);
    });
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.$exception->getMessage();
}
file_put_contents($config['result_file'], json_encode($result), LOCK_EX);
