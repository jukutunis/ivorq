<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GuestPaymentMigrationProofTest extends PostgresTestCase
{
    use CreatesGuestLedgerFolioData;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();
        $this->actingAs($this->glfActor);
    }

    public function test_guest_payment_tables_columns_constraints_and_indexes_exist(): void
    {
        foreach (['guest_payment_transactions', 'guest_payment_allocations', 'guest_payment_reversals'] as $table) {
            $this->assertTrue(Schema::hasTable($table), "{$table} must exist.");
        }

        foreach ([
            'source_domain',
            'source_type',
            'source_id',
            'reverses_folio_item_id',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('folio_items', $column), "folio_items.{$column} must exist.");
        }

        foreach ([
            'guest_payments_amount_positive_check',
            'guest_payments_tender_check',
            'guest_payments_status_check',
            'guest_allocations_amount_positive_check',
            'guest_reversals_amount_positive_check',
            'guest_reversals_type_check',
            'guest_reversals_reference_check',
            'folio_items_source_all_or_none_check',
            'folio_items_guest_payment_source_check',
            'folio_items_payment_reversal_link_check',
        ] as $constraint) {
            $this->assertTrue($this->constraintExists($constraint), "{$constraint} must exist.");
        }

        foreach ([
            'guest_payments_property_number_unique',
            'guest_payments_property_idem_unique',
            'guest_allocations_property_idem_unique',
            'guest_reversals_property_idem_unique',
            'guest_reversals_one_void_per_payment_unique',
            'guest_reversals_one_reversal_per_allocation_unique',
            'folio_items_property_source_unique',
        ] as $index) {
            $this->assertTrue($this->indexExists($index), "{$index} must exist.");
        }
    }

    public function test_constraints_reject_invalid_guest_payment_and_source_identity_shapes(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->makeGlfFolio($reservation, $reservation->primaryGuest);
        $now = now();

        $this->expectException(QueryException::class);

        DB::table('folio_items')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $this->glfProperty->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Payment->value,
            'description' => 'Broken source shape',
            'quantity' => '1.00',
            'amount' => '-10.00',
            'is_void' => false,
            'posted_at' => $now,
            'posted_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id,
            'source_domain' => 'pms_cashiering',
            'source_type' => 'guest_payment_allocation',
            'source_id' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ]);
    }

    public function test_pre_existing_ambiguous_payment_and_deposit_items_are_absent_after_migration(): void
    {
        $this->assertSame(0, DB::table('folio_items')->whereIn('item_type', ['payment', 'deposit'])->whereNull('source_id')->count());
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }

    private function indexExists(string $name): bool
    {
        return DB::table('pg_indexes')->where('indexname', $name)->exists();
    }
}
