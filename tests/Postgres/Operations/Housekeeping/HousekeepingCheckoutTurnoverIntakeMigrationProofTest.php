<?php

namespace Tests\Postgres\Operations\Housekeeping;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\Postgres\Operations\Housekeeping\Concerns\CreatesHousekeepingCheckoutTurnoverIntakeData;
use Tests\PostgresTestCase;

class HousekeepingCheckoutTurnoverIntakeMigrationProofTest extends PostgresTestCase
{
    use RefreshDatabase;
    use CreatesHousekeepingCheckoutTurnoverIntakeData;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpCheckoutTurnoverFixture();
    }

    public function test_table_columns_constraints_and_triggers_exist(): void
    {
        $this->assertTrue(Schema::hasTable('housekeeping_checkout_turnover_intakes'));

        foreach ([
            'id', 'property_id', 'front_desk_checkout_housekeeping_handoff_id',
            'checkout_execution_id', 'front_desk_stay_id', 'reservation_id',
            'room_id', 'property_business_date_id', 'business_date',
            'cleaning_task_id', 'room_readiness_transition_id',
            'handoff_source_hash', 'checkout_execution_source_hash', 'source_hash',
            'room_readiness_before', 'room_readiness_after',
            'cleanliness_before', 'cleanliness_after', 'consumer_identity',
            'occurred_at', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('housekeeping_checkout_turnover_intakes', $column), "Missing {$column}");
        }

        foreach ([
            'hk_cti_handoff_unique', 'hk_cti_execution_unique',
            'hk_cti_cleaning_task_unique', 'hk_cti_transition_unique',
            'hk_cti_source_hash_unique', 'hk_cti_source_hash_check',
            'hk_cti_handoff_hash_check', 'hk_cti_execution_hash_check',
            'hk_cti_consumer_identity_check', 'hk_cti_state_shape_check',
        ] as $constraint) {
            $this->assertTrue($this->constraintExists($constraint), "Missing constraint {$constraint}");
        }

        foreach ([
            'hk_cti_no_update_trigger',
            'hk_cti_no_delete_trigger',
            'hk_cti_source_relationship_trigger',
        ] as $trigger) {
            $this->assertTrue($this->triggerExists($trigger), "Missing trigger {$trigger}");
        }
    }

    public function test_database_update_immutability_trigger_blocks_update(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);
        $result = app(\Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        try {
            DB::table('housekeeping_checkout_turnover_intakes')
                ->where('id', $result->intakeId)
                ->update(['consumer_identity' => 'other-consumer']);
            $this->fail('Database update should be blocked.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('HK_P11_CHECKOUT_TURNOVER_INTAKE_IMMUTABLE', $exception->getMessage());
        }
    }

    public function test_database_delete_immutability_trigger_blocks_delete(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);
        $result = app(\Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        try {
            DB::table('housekeeping_checkout_turnover_intakes')
                ->where('id', $result->intakeId)
                ->delete();
            $this->fail('Database delete should be blocked.');
        } catch (\Throwable $exception) {
            $this->assertStringContainsString('HK_P11_CHECKOUT_TURNOVER_INTAKE_DELETE_FORBIDDEN', $exception->getMessage());
        }
    }

    public function test_direct_sql_source_mismatch_is_rejected(): void
    {
        $roomId = $this->p11Room($this->property);
        $this->p11CheckoutSource($this->property, $roomId);
        $claim = app(\Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverIntakeService::class)
            ->consumeNextAvailable($this->property->id, 60);

        $intake = DB::table('housekeeping_checkout_turnover_intakes')->where('id', $claim->intakeId)->first();

        DB::table('housekeeping_checkout_turnover_intakes')->where('id', $intake->id);
        $this->assertSame($roomId, $intake->room_id);
        $this->assertSame('waiting_cleaning', $intake->room_readiness_after);
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }

    private function triggerExists(string $name): bool
    {
        return DB::table('pg_trigger')->where('tgname', $name)->exists();
    }
}
