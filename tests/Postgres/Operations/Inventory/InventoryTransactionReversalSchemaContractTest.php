<?php

namespace Tests\Postgres\Operations\Inventory;

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\ValueObjects\InventoryReversalPostingIntent;

class InventoryTransactionReversalSchemaContractTest extends InventoryReversalPostingServiceTest
{
    public function test_postgres_test_target_verified(): void
    {
        $this->assertSame('testing', config('app.env'));
        $this->assertSame('pgsql', config('database.default'));
        $this->assertSame('ivorq_testing', config('database.connections.pgsql.database'));
    }

    public function test_anti_double_reversal_partial_unique_index_definition_is_preserved(): void
    {
        $index = DB::selectOne(<<<'SQL'
            SELECT indexdef
              FROM pg_indexes
             WHERE tablename = 'inventory_transactions'
               AND indexname = 'idx_inventory_transactions_reversal_limit'
            SQL);

        $this->assertNotNull($index);
        $definition = strtolower($index->indexdef);
        $this->assertStringContainsString('unique index', $definition);
        $this->assertStringContainsString('reverses_inventory_transaction_id', $definition);
        $this->assertStringContainsString('where (reverses_inventory_transaction_id is not null)', $definition);
    }

    public function test_self_reversal_constraint_definition_is_preserved(): void
    {
        $constraint = DB::selectOne(<<<'SQL'
            SELECT pg_get_constraintdef(c.oid) AS definition
              FROM pg_constraint AS c
              JOIN pg_class AS t ON c.conrelid = t.oid
             WHERE t.relname = 'inventory_transactions'
               AND c.conname = 'chk_inventory_transactions_no_self_reversal'
               AND c.contype = 'c'
            SQL);

        $this->assertNotNull($constraint);
        $definition = strtolower(str_replace([' ', '"'], '', $constraint->definition));
        $this->assertStringContainsString('reverses_inventory_transaction_idisnull', $definition);
        $this->assertStringContainsString('reverses_inventory_transaction_id<>id', $definition);
    }

    public function test_reversal_integrity_and_delivery_stamp_constraints_are_present(): void
    {
        $constraints = DB::table('pg_constraint as c')
            ->join('pg_class as relation', 'relation.oid', '=', 'c.conrelid')
            ->where('relation.relname', 'inventory_transactions')
            ->pluck('c.conname')
            ->all();

        $this->assertContains('chk_inv_tx_cost_delivery_stamp', $constraints);
        $this->assertContains('chk_inventory_transactions_no_self_reversal', $constraints);

        $indexes = DB::table('pg_indexes')
            ->where('tablename', 'inventory_transactions')
            ->pluck('indexname')
            ->all();
        $this->assertContains('idx_inventory_transactions_idempotency', $indexes);
        $this->assertContains('idx_inventory_transactions_reversal_limit', $indexes);
        $this->assertContains('idx_inv_tx_cost_delivery_mode', $indexes);
        $this->assertContains('idx_inv_tx_cost_delivery_cutover', $indexes);
    }

    public function test_original_and_reversal_are_immutable_sources_with_exact_link_and_sequence(): void
    {
        $groupId = $this->seedGroup();
        $snapshotId = $this->seedSnapshot($groupId);
        $this->seedState($groupId, $snapshotId, '10.0000', '100.0000', 1, now()->toDateString());
        $this->seedSynchronousControl($groupId, 1);
        $this->seedStock('13.0000');
        $original = $this->createTransaction(TransactionTypeEnum::PurchaseReceipt);

        $result = $this->service->post(new InventoryReversalPostingIntent(
            originalTransactionId: $original->id,
            idempotencyKey: 'p01d-schema-contract',
            actorId: $this->user->id,
            approvalReference: 'OWNER-CC-P01D-SCHEMA',
            reversalReason: 'Schema contract proof',
        ));

        $reversal = $result->reversalTransaction;
        $this->assertSame($original->id, $reversal->reverses_inventory_transaction_id);
        $this->assertSame(2, $reversal->valuation_sequence);
        $this->assertSame('13.0000', (string) $reversal->quantity_before);
        $this->assertSame('8.0000', (string) $reversal->quantity_after);
        $this->assertSame('8.0000', (string) DB::table('inventory_stocks')->value('physical_quantity'));

        try {
            DB::table('inventory_transactions')->where('id', $original->id)->update([
                'quantity_change' => '999.0000',
            ]);
            $this->fail('Expected original source immutability to reject mutation.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('immutable', $exception->getMessage());
        }
    }
}
