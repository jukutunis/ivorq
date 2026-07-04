<?php

namespace Tests\Postgres\Foundation\Authorization;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Database\Seeders\PermissionSeeder;
use Modules\Foundation\Authorization\Models\Permission;
use Tests\PostgresTestCase;

class FxAdjustmentCandidatePermissionFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    public function test_seeding_registers_fx_candidate_permission(): void
    {
        // 1. Assert pre-state: table empty or not seeded
        Permission::query()->delete();
        $this->assertDatabaseCount('permissions', 0);

        // 2. Seed permissions
        $this->seed(PermissionSeeder::class);

        // 3. Verify exactly the new permission is registered with correct guard
        $this->assertDatabaseHas('permissions', [
            'name' => 'finance.fx-adjustment-candidate.create',
            'guard_name' => 'web',
        ]);

        // 4. Repeated seeding is idempotent (does not create duplicate records)
        $countAfterFirst = Permission::count();
        $this->seed(PermissionSeeder::class);
        $this->assertSame($countAfterFirst, Permission::count());

        // 5. Verify adjacent permissions are untouched
        $this->assertDatabaseHas('permissions', [
            'name' => 'finance.fx-rate.approve',
            'guard_name' => 'web',
        ]);
        $this->assertDatabaseHas('permissions', [
            'name' => 'finance.payment-adjustment-config.record',
            'guard_name' => 'web',
        ]);

        // 6. Prove no entities are created or mutated by this permission seeder run
        $this->assertDatabaseCount('properties', 0);
        $this->assertDatabaseCount('users', 0);
        $this->assertDatabaseCount('journal_candidates', 0);
        $this->assertDatabaseCount('gl_journal_entries', 0);
        $this->assertDatabaseCount('payment_proposals', 0);
        $this->assertDatabaseCount('ap_settlement_allocations', 0);
        $this->assertDatabaseCount('cashbook_transactions', 0);
        $this->assertDatabaseCount('exchange_rate_evidences', 0);
        $this->assertDatabaseCount('payment_adjustment_configuration_evidences', 0);
    }
}
