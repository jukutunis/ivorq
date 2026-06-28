<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\CostControl\Models\CostAuthorityEnrollmentGroup;
use Modules\Finance\CostControl\Repositories\CostAuthorityEnrollmentRepository;
use Modules\Finance\GeneralLedger\Services\VariancePostingEngine;
use Modules\Operations\Inventory\Enums\TransactionTypeEnum;
use Modules\Operations\Inventory\Models\InventoryTransaction;
use RuntimeException;
use Tests\PostgresTestCase;

class VariancePostingEngineEnrollmentGuardTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private VariancePostingEngine              $engine;
    private CostAuthorityEnrollmentRepository  $enrollmentRepository;

    private string $propertyId;
    private string $itemId;
    private string $locationId;
    private string $actorId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->engine                = app(VariancePostingEngine::class);
        $this->enrollmentRepository  = app(CostAuthorityEnrollmentRepository::class);

        $this->propertyId  = (string) Str::ulid();
        $this->itemId      = (string) Str::ulid();
        $this->locationId  = (string) Str::ulid();
        $this->actorId     = (string) Str::ulid();

        // Insert a real property so JournalCandidate FK is satisfied on non-ENROLLED paths.
        DB::table('properties')->insert([
            'id'         => $this->propertyId,
            'name'       => 'Enrollment Guard Test Property',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Proof 1: no enrollment group — legacy variance candidate is available
    // -------------------------------------------------------------------------
    public function test_no_enrollment_group_allows_legacy_variance_posting(): void
    {
        $tx = $this->makeTransaction($this->propertyId, $this->itemId);

        // Must not throw; engine proceeds and creates a JournalCandidate
        // (CONFIGURATION_ERROR due to missing GL mappings is acceptable).
        $this->engine->process($tx);

        $this->assertEquals(
            1,
            DB::table('journal_candidates')->where('source_id', $tx->id)->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 2: APPROVED enrollment group — does NOT block legacy variance posting
    // -------------------------------------------------------------------------
    public function test_approved_enrollment_group_does_not_block_legacy_variance_posting(): void
    {
        $locationId = (string) Str::ulid();
        $group = $this->enrollmentRepository->createDraft(
            ['property_id' => $this->propertyId, 'item_id' => $this->itemId],
            [$this->makeSnapshot($this->propertyId, $locationId, $this->itemId)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepository->approve($group->id, $this->actorId, now())
        );

        // Group is APPROVED (not ENROLLED) — guard must not fire.
        $tx = $this->makeTransaction($this->propertyId, $this->itemId);
        $this->engine->process($tx);

        $this->assertEquals(
            1,
            DB::table('journal_candidates')->where('source_id', $tx->id)->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 3: ENROLLED enrollment group — engine fails closed before JournalCandidate
    // -------------------------------------------------------------------------
    public function test_enrolled_group_blocks_legacy_variance_posting_with_exception(): void
    {
        $this->createEnrolledGroup($this->propertyId, $this->itemId);

        $tx = $this->makeTransaction($this->propertyId, $this->itemId);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessageMatches('/CostControl authority is enrolled/');

        $this->engine->process($tx);
    }

    // -------------------------------------------------------------------------
    // Proof 4: ENROLLED group for a DIFFERENT item — does not block this transaction
    // -------------------------------------------------------------------------
    public function test_enrolled_group_for_different_item_does_not_block(): void
    {
        $differentItemId = (string) Str::ulid();
        $this->createEnrolledGroup($this->propertyId, $differentItemId);

        $tx = $this->makeTransaction($this->propertyId, $this->itemId);

        // Guard must not fire: enrolled scope covers a different item.
        $this->engine->process($tx);

        $this->assertEquals(
            1,
            DB::table('journal_candidates')->where('source_id', $tx->id)->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 5: ENROLLED group for a DIFFERENT property — does not block this transaction
    // -------------------------------------------------------------------------
    public function test_enrolled_group_for_different_property_does_not_block(): void
    {
        $differentPropertyId = (string) Str::ulid();
        $this->createEnrolledGroup($differentPropertyId, $this->itemId);

        $tx = $this->makeTransaction($this->propertyId, $this->itemId);

        // Guard must not fire: enrolled scope covers a different property.
        $this->engine->process($tx);

        $this->assertEquals(
            1,
            DB::table('journal_candidates')->where('source_id', $tx->id)->count()
        );
    }

    // -------------------------------------------------------------------------
    // Proof 6: Blocked ENROLLED scope — no side-effect records created or modified
    // -------------------------------------------------------------------------
    public function test_blocked_enrolled_scope_creates_no_side_effect_records(): void
    {
        $this->createEnrolledGroup($this->propertyId, $this->itemId);

        $tx = $this->makeTransaction($this->propertyId, $this->itemId);

        // Snapshot counts before guard fires.
        $candidatesBefore    = DB::table('journal_candidates')->count();
        $candidateLinesBefore = DB::table('journal_candidate_lines')->count();
        $journalsBefore      = DB::table('gl_journal_entries')
            ->where('property_id', $this->propertyId)->count();
        $ledgerBefore        = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)->count();
        $avcoStatesBefore    = DB::table('cost_avco_states')
            ->where('property_id', $this->propertyId)->count();
        $inventoryTxBefore   = DB::table('inventory_transactions')
            ->where('property_id', $this->propertyId)->count();

        // Enrollment group count before (must remain unchanged).
        $enrollmentsBefore = DB::table('cost_authority_enrollment_groups')
            ->where('property_id', $this->propertyId)
            ->where('item_id', $this->itemId)
            ->count();

        try {
            $this->engine->process($tx);
            $this->fail('Expected RuntimeException from enrollment guard; none thrown.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('CostControl authority is enrolled', $e->getMessage());
        }

        // No JournalCandidate created for this transaction.
        $this->assertEquals(
            0,
            DB::table('journal_candidates')->where('source_id', $tx->id)->count()
        );

        // Total counts unchanged.
        $this->assertEquals($candidatesBefore,     DB::table('journal_candidates')->count());
        $this->assertEquals($candidateLinesBefore,  DB::table('journal_candidate_lines')->count());
        $this->assertEquals($journalsBefore,
            DB::table('gl_journal_entries')->where('property_id', $this->propertyId)->count()
        );
        $this->assertEquals($ledgerBefore,
            DB::table('gl_ledger_balances')->where('property_id', $this->propertyId)->count()
        );
        $this->assertEquals($avcoStatesBefore,
            DB::table('cost_avco_states')->where('property_id', $this->propertyId)->count()
        );
        $this->assertEquals($inventoryTxBefore,
            DB::table('inventory_transactions')->where('property_id', $this->propertyId)->count()
        );

        // Enrollment group is unchanged: still exactly 1 ENROLLED record.
        $this->assertEquals(
            $enrollmentsBefore,
            DB::table('cost_authority_enrollment_groups')
                ->where('property_id', $this->propertyId)
                ->where('item_id', $this->itemId)
                ->count()
        );
        $this->assertEquals(
            1,
            DB::table('cost_authority_enrollment_groups')
                ->where('property_id', $this->propertyId)
                ->where('item_id', $this->itemId)
                ->where('status', 'enrolled')
                ->count()
        );
    }

    // -------------------------------------------------------------------------
    // Internal helpers
    // -------------------------------------------------------------------------

    private function makeTransaction(string $propertyId, string $itemId): InventoryTransaction
    {
        $tx = new InventoryTransaction();
        $tx->id               = (string) Str::ulid();
        $tx->property_id      = $propertyId;
        $tx->item_id          = $itemId;
        $tx->location_id      = $this->locationId;
        $tx->transaction_type = TransactionTypeEnum::AdjustmentIn;
        $tx->quantity_before  = '10.0000';
        $tx->quantity_change  = '5.0000';
        $tx->quantity_after   = '15.0000';
        $tx->total_cost       = '50.00';
        $tx->posted_at        = now();
        $tx->posted_by        = null;
        $tx->reference_id     = null;
        $tx->unit_cost        = '10.00';
        return $tx;
    }

    private function makeSnapshot(string $propertyId, string $locationId, string $itemId): array
    {
        return [
            'location_id'            => $locationId,
            'valuation_scope'        => "property:{$propertyId}:location:{$locationId}:item:{$itemId}",
            'opening_quantity'       => '100.0000',
            'opening_carrying_value' => '1500.0000',
            'currency_code'          => 'USD',
            'business_date'          => '2026-07-01',
            'financial_period_id'    => (string) Str::ulid(),
            'evidence_timestamp'     => now(),
        ];
    }

    private function createEnrolledGroup(string $propertyId, string $itemId): CostAuthorityEnrollmentGroup
    {
        $locationId = (string) Str::ulid();

        $group = $this->enrollmentRepository->createDraft(
            ['property_id' => $propertyId, 'item_id' => $itemId],
            [$this->makeSnapshot($propertyId, $locationId, $itemId)]
        );

        DB::transaction(
            fn () => $this->enrollmentRepository->approve($group->id, $this->actorId, now())
        );

        DB::table('cost_authority_enrollment_groups')
            ->where('id', $group->id)
            ->update([
                'status'      => 'enrolled',
                'enrolled_at' => now(),
                'updated_at'  => now(),
            ]);

        return CostAuthorityEnrollmentGroup::find($group->id);
    }
}
