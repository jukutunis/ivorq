<?php

declare(strict_types=1);
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Inventory\Services\InventoryReversalPostingService;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;
use Shared\Services\CurrentPropertyService;

$configPath = $argv[1] ?? '';
$config = is_file($configPath) ? json_decode((string) file_get_contents($configPath), true) : null;
if (! is_array($config)) {
    exit(2);
}

require $config['base_path'].'/vendor/autoload.php';
$app = require $config['base_path'].'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();
config(['database.connections.pgsql.database' => $config['db_name']]);
DB::purge('pgsql');
DB::reconnect('pgsql');

$result = [
    'pid' => getmypid(),
    'pg_pid' => null,
    'action' => $config['action'],
    'outcome' => 'ERROR',
    'source_id' => null,
    'mode' => null,
    'error' => null,
];

try {
    $result['pg_pid'] = DB::selectOne('SELECT pg_backend_pid() AS pid')->pid;
    $actor = User::findOrFail($config['actor_id']);
    Auth::login($actor);
    app(CurrentPropertyService::class)->setPropertyId($config['property_id']);

    touch($config['barrier_dir'].'/ready-'.$config['worker_id']);
    $start = $config['barrier_dir'].'/start.signal';
    for ($attempt = 0; $attempt < 12000 && ! is_file($start); $attempt++) {
        usleep(10000);
    }
    touch($config['barrier_dir'].'/calling-'.$config['worker_id']);

    if ($config['action'] === 'post') {
        $posting = app(InventoryReversalPostingService::class);
        $posted = $posting->post(new InventoryReversalPostingIntent(
            originalTransactionId: $config['original_id'],
            idempotencyKey: $config['idempotency_key'],
            actorId: $config['actor_id'],
            approvalReference: 'OWNER-CC-P01D-CONCURRENCY',
            reversalReason: 'CC-P01D concurrency proof',
        ));
        $result['outcome'] = $posted->replayed ? 'REPLAYED' : 'POSTED';
        $result['source_id'] = $posted->reversalTransaction->id;
        $result['mode'] = $posted->reversalTransaction->cost_delivery_mode;
    } elseif ($config['action'] === 'cutover') {
        $cutoverId = DB::transaction(function () use ($config): string {
            $ownership = DB::table('cost_delivery_mode_ownerships')
                ->where('id', $config['ownership_id'])->lockForUpdate()->first();
            if ($ownership === null || $ownership->delivery_mode !== 'SYNCHRONOUS') {
                throw new RuntimeException('CUTOVER_OWNERSHIP_NOT_SYNCHRONOUS');
            }

            $allocator = DB::table('inventory_valuation_sequences')
                ->where('property_id', $config['property_id'])
                ->where('location_id', $config['location_id'])
                ->where('item_id', $config['item_id'])->lockForUpdate()->first();
            $state = DB::table('cost_avco_states')
                ->where('property_id', $config['property_id'])
                ->where('location_id', $config['location_id'])
                ->where('item_id', $config['item_id'])->lockForUpdate()->first();
            $sequence = (int) $allocator->last_sequence;
            if ((int) $state->last_valuation_sequence !== $sequence) {
                throw new RuntimeException('CUTOVER_SEQUENCE_STATE_DIVERGENCE');
            }

            $now = now();
            $cutoverId = (string) Str::ulid();
            DB::table('cost_delivery_pilot_properties')->insert([
                'id' => (string) Str::ulid(),
                'pilot_slot' => 1,
                'property_id' => $config['property_id'],
                'owner_approval_reference' => 'OWNER-CC-P01D-CONCURRENCY',
                'authorized_by' => $config['actor_id'],
                'authorized_at' => $now,
                'created_at' => $now,
            ]);
            DB::table('cost_delivery_cutovers')->insert([
                'id' => $cutoverId,
                'ownership_id' => $config['ownership_id'],
                'enrollment_group_id' => $config['group_id'],
                'property_id' => $config['property_id'],
                'item_id' => $config['item_id'],
                'financial_period_id' => $config['period_id'],
                'boundary_business_date' => $config['business_date'],
                'owner_approval_reference' => 'OWNER-CC-P01D-CONCURRENCY',
                'requested_by' => $config['actor_id'],
                'requested_at' => $now->copy()->subMinutes(2),
                'approved_by' => $config['actor_id'],
                'approved_at' => $now->copy()->subMinute(),
                'activated_by' => $config['actor_id'],
                'activated_at' => $now,
                'created_at' => $now,
            ]);
            DB::table('cost_delivery_cutover_scopes')->insert([
                'id' => (string) Str::ulid(),
                'cutover_id' => $cutoverId,
                'enrollment_scope_snapshot_id' => $config['snapshot_id'],
                'property_id' => $config['property_id'],
                'location_id' => $config['location_id'],
                'item_id' => $config['item_id'],
                'valuation_scope' => $config['valuation_scope'],
                'inventory_sequence_source' => 'ALLOCATOR_ROW',
                'inventory_valuation_sequence_id' => $allocator->id,
                'inventory_allocator_last_sequence' => $sequence,
                'cost_avco_last_valuation_sequence' => $sequence,
                'sequence_state_classification' => 'PRIOR_APPLIED_VALUATION_SEQUENCE',
                'last_synchronously_owned_sequence' => $sequence,
                'first_deferred_owned_sequence' => $sequence + 1,
                'created_at' => $now,
            ]);
            DB::table('cost_delivery_mode_ownerships')
                ->where('id', $config['ownership_id'])->update([
                    'delivery_mode' => 'DEFERRED',
                    'ownership_version' => 2,
                    'activated_cutover_id' => $cutoverId,
                    'changed_by' => $config['actor_id'],
                    'changed_at' => $now,
                    'updated_at' => $now,
                ]);
            DB::statement('SET CONSTRAINTS ALL IMMEDIATE');

            return $cutoverId;
        });
        $result['outcome'] = 'CUTOVER';
        $result['source_id'] = $cutoverId;
        $result['mode'] = 'DEFERRED';
    }
} catch (Throwable $exception) {
    $result['error'] = get_class($exception).': '.substr($exception->getMessage(), 0, 500);
}

file_put_contents($config['result_file'], json_encode($result, JSON_PRETTY_PRINT), LOCK_EX);
touch($config['barrier_dir'].'/done-'.$config['worker_id']);
