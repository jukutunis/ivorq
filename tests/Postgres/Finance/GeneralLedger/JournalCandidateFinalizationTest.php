<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\JournalEntryLine;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Services\JournalCandidateFinalizationService;
use Modules\Finance\GeneralLedger\Services\JournalCandidateService;
use Modules\Foundation\User\Models\User;
use Tests\PostgresTestCase;

class JournalCandidateFinalizationTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private JournalCandidateFinalizationService $finalizationService;
    private string $propertyId;
    private string $actorId;
    private string $assetAccountId;
    private string $revenueAccountId;
    private string $financialPeriodId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->finalizationService = app(JournalCandidateFinalizationService::class);

        $this->propertyId = (string) Str::ulid();
        $this->actorId = (string) Str::ulid();

        // Ensure property exists
        DB::table('properties')->insert([
            'id' => $this->propertyId,
            'name' => 'Test Property',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // Create user and actingAs
        $user = User::first() ?? User::create([
            'id' => $this->actorId,
            'name' => 'Test User',
            'email' => 'test-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user);

        // Create accounts
        $assetAccount = Account::create([
            'property_id' => $this->propertyId,
            'code' => '1000',
            'name' => 'Inventory Asset',
            'account_type' => AccountTypeEnum::Asset->value,
            'account_category' => 'CurrentAsset',
            'normal_balance' => 'Debit',
            'is_active' => true,
        ]);
        $this->assetAccountId = $assetAccount->id;

        $revenueAccount = Account::create([
            'property_id' => $this->propertyId,
            'code' => '4000',
            'name' => 'Inventory Gain',
            'account_type' => AccountTypeEnum::Revenue->value,
            'account_category' => 'Revenue',
            'normal_balance' => 'Credit',
            'is_active' => true,
        ]);
        $this->revenueAccountId = $revenueAccount->id;

        // Create mappings
        OperationalIdentityMapping::create([
            'property_id' => $this->propertyId,
            'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
            'account_id' => $this->assetAccountId,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        OperationalIdentityMapping::create([
            'property_id' => $this->propertyId,
            'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value,
            'account_id' => $this->revenueAccountId,
            'effective_from' => '2026-01-01',
            'is_active' => true,
        ]);

        // Create open financial period for 2026-07
        $this->financialPeriodId = (string) Str::ulid();
        DB::table('gl_financial_periods')->insert([
            'id' => $this->financialPeriodId,
            'property_id' => $this->propertyId,
            'period_year' => 2026,
            'period_month' => 7,
            'status' => 'Open',
            'opened_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function makeApprovedCandidate(string $sourceType = 'InventoryTransaction', array $lines = []): JournalCandidate
    {
        $candidateId = (string) Str::ulid();
        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $this->propertyId,
            'source_type' => $sourceType,
            'source_id' => (string) Str::ulid(),
            'posting_event' => 'InventoryAdjustmentVariance',
            'status' => JournalCandidateStatusEnum::APPROVED->value,
            'candidate_date' => '2026-07-01',
            'description' => 'Test approved candidate',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $candidate = JournalCandidate::find($candidateId);

        if (empty($lines)) {
            $lines = [
                [
                    'id' => (string) Str::ulid(),
                    'journal_candidate_id' => $candidateId,
                    'operational_identity' => OperationalIdentityEnum::INVENTORY->value,
                    'entry_type' => EntryTypeEnum::DEBIT->value,
                    'amount' => '100.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ],
                [
                    'id' => (string) Str::ulid(),
                    'journal_candidate_id' => $candidateId,
                    'operational_identity' => OperationalIdentityEnum::INVENTORY_ADJUSTMENT_GAIN->value,
                    'entry_type' => EntryTypeEnum::CREDIT->value,
                    'amount' => '100.0000',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]
            ];
        }

        foreach ($lines as $line) {
            DB::table('journal_candidate_lines')->insert($line);
        }

        return $candidate->load('lines');
    }

    // -------------------------------------------------------------------------
    // Test 1 & 2: APPROVED candidate finalization flows and LedgerBalance impact
    // -------------------------------------------------------------------------
    public function test_approved_candidate_finalizes_completely(): void
    {
        $candidate = $this->makeApprovedCandidate();

        $journal = $this->finalizationService->finalize($candidate->id);

        $this->assertEquals(JournalStatusEnum::Posted, $journal->status);
        $this->assertEquals($candidate->id, $journal->journal_candidate_id);
        $this->assertEquals('Inventory', $journal->source_module);

        // Verify candidate status updated to POSTED
        $this->assertEquals(JournalCandidateStatusEnum::POSTED, $candidate->fresh()->status);

        // Verify line counts and values
        $this->assertCount(2, $journal->lines);
        $this->assertEquals('100.00', $journal->lines->where('account_id', $this->assetAccountId)->first()->debit_amount);
        $this->assertEquals('100.00', $journal->lines->where('account_id', $this->revenueAccountId)->first()->credit_amount);

        // Verify LedgerBalance impact
        $balanceAsset = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->where('account_id', $this->assetAccountId)
            ->first();
        $this->assertEquals('100.00', $balanceAsset->debit_total);
        $this->assertEquals('100.00', $balanceAsset->ending_balance);
    }

    // -------------------------------------------------------------------------
    // Test 3: Second finalization returns existing JournalEntry without duplicates
    // -------------------------------------------------------------------------
    public function test_duplicate_finalization_returns_existing_without_reposting(): void
    {
        $candidate = $this->makeApprovedCandidate();

        $journal1 = $this->finalizationService->finalize($candidate->id);
        $journal2 = $this->finalizationService->finalize($candidate->id);

        $this->assertEquals($journal1->id, $journal2->id);
        $this->assertCount(1, JournalEntry::where('journal_candidate_id', $candidate->id)->get());
    }

    // -------------------------------------------------------------------------
    // Test 4: Atomicity on failure: no journal, lines, balance, candidate posted
    // -------------------------------------------------------------------------
    public function test_finalization_failure_rolls_back_entirely(): void
    {
        $candidate = $this->makeApprovedCandidate();

        // Insert non-representable line amount to force controlled failure after draft creation
        DB::table('journal_candidate_lines')
            ->where('journal_candidate_id', $candidate->id)
            ->update(['amount' => '100.1234']);

        $candidate->refresh();

        try {
            $this->finalizationService->finalize($candidate->id);
            $this->fail('Finalization should have failed due to precision.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is not representable', $e->getMessage());
        }

        // Verify candidate not changed to POSTED
        $this->assertEquals(JournalCandidateStatusEnum::APPROVED, $candidate->fresh()->status);

        // Verify no JournalEntry created
        $this->assertCount(0, JournalEntry::where('journal_candidate_id', $candidate->id)->get());

        // Verify no LedgerBalance mutation
        $balance = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->first();
        $this->assertNull($balance);
    }

    // -------------------------------------------------------------------------
    // Test 5: POSTED_LEGACY cannot be finalized or rejected
    // -------------------------------------------------------------------------
    public function test_posted_legacy_cannot_be_finalized_or_rejected(): void
    {
        $candidate = $this->makeApprovedCandidate();
        DB::table('journal_candidates')
            ->where('id', $candidate->id)
            ->update(['status' => JournalCandidateStatusEnum::POSTED_LEGACY->value]);

        try {
            $this->finalizationService->finalize($candidate->id);
            $this->fail('Finalizing POSTED_LEGACY should fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('POSTED_LEGACY', $e->getMessage());
        }

        $service = app(JournalCandidateService::class);
        try {
            $service->reject($candidate->id, 'Testing rejection on legacy');
            $this->fail('Rejecting POSTED_LEGACY should fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('cannot be rejected', $e->getMessage());
        }
    }

    // -------------------------------------------------------------------------
    // Test 6: Header UPDATE / soft-delete after Posted is rejected by PostgreSQL
    // -------------------------------------------------------------------------
    public function test_postgres_trigger_blocks_updating_posted_journal_entry(): void
    {
        $candidate = $this->makeApprovedCandidate();
        $journal = $this->finalizationService->finalize($candidate->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/immutable and cannot be updated/');

        DB::table('gl_journal_entries')
            ->where('id', $journal->id)
            ->update(['description' => 'Forced Update']);
    }

    public function test_postgres_trigger_blocks_deleting_posted_journal_entry(): void
    {
        $candidate = $this->makeApprovedCandidate();
        $journal = $this->finalizationService->finalize($candidate->id);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/immutable and cannot be deleted/');

        DB::table('gl_journal_entries')
            ->where('id', $journal->id)
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Test 7: Final-line INSERT / UPDATE / DELETE after parent Posted is rejected
    // -------------------------------------------------------------------------
    public function test_postgres_trigger_blocks_line_changes_after_posted(): void
    {
        $candidate = $this->makeApprovedCandidate();
        $journal = $this->finalizationService->finalize($candidate->id);

        // Attempt Line INSERT
        try {
            DB::table('gl_journal_entry_lines')->insert([
                'id' => (string) Str::ulid(),
                'property_id' => $this->propertyId,
                'journal_entry_id' => $journal->id,
                'account_id' => $this->assetAccountId,
                'debit_amount' => '50.00',
                'credit_amount' => '0.00',
            ]);
            $this->fail('Line insert should have failed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('Cannot insert a line into a posted journal entry', $e->getMessage());
        }

        // Attempt Line UPDATE
        $line = $journal->lines->first();
        try {
            DB::table('gl_journal_entry_lines')
                ->where('id', $line->id)
                ->update(['memo' => 'Updated Memo']);
            $this->fail('Line update should have failed.');
        } catch (\Illuminate\Database\QueryException $e) {
            $this->assertStringContainsString('Cannot update a line of a posted journal entry', $e->getMessage());
        }

        // Attempt Line DELETE
        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/Cannot delete a line of a posted journal entry/');

        DB::table('gl_journal_entry_lines')
            ->where('id', $line->id)
            ->delete();
    }

    // -------------------------------------------------------------------------
    // Test 8: Invalid final-line values are rejected by check constraints
    // -------------------------------------------------------------------------
    public function test_postgres_checks_reject_negative_amounts(): void
    {
        $journal = JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status' => JournalStatusEnum::Draft,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/debit_amount_non_negative/');

        DB::table('gl_journal_entry_lines')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'journal_entry_id' => $journal->id,
            'account_id' => $this->assetAccountId,
            'debit_amount' => '-10.00', // negative debit - constraint triggers
            'credit_amount' => '0.00',
        ]);
    }

    public function test_postgres_checks_reject_double_zero_amounts(): void
    {
        $journal = JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status' => JournalStatusEnum::Draft,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/single_active_side/');

        DB::table('gl_journal_entry_lines')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'journal_entry_id' => $journal->id,
            'account_id' => $this->assetAccountId,
            'debit_amount' => '0.00', // both zero
            'credit_amount' => '0.00',
        ]);
    }

    public function test_postgres_checks_reject_double_positive_amounts(): void
    {
        $journal = JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status' => JournalStatusEnum::Draft,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/single_active_side/');

        DB::table('gl_journal_entry_lines')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'journal_entry_id' => $journal->id,
            'account_id' => $this->assetAccountId,
            'debit_amount' => '10.00', // both positive
            'credit_amount' => '10.00',
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 9: Candidate canonical duplicate creation blocked by unique index
    // -------------------------------------------------------------------------
    public function test_candidate_duplicate_creation_blocked_by_database_uniqueness(): void
    {
        $sourceId = (string) Str::ulid();

        DB::table('journal_candidates')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'source_type' => 'InventoryTransaction',
            'source_id' => $sourceId,
            'posting_event' => 'InventoryAdjustmentVariance',
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'candidate_date' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/uk_journal_candidates_canonical_identity/');

        // Try inserting duplicate source
        DB::table('journal_candidates')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->propertyId,
            'source_type' => 'InventoryTransaction',
            'source_id' => $sourceId,
            'posting_event' => 'InventoryAdjustmentVariance',
            'status' => JournalCandidateStatusEnum::DRAFT->value,
            'candidate_date' => '2026-07-01',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Test 10: Candidate-origin and direct-subledger indexes do not conflict
    // -------------------------------------------------------------------------
    public function test_candidate_origin_and_direct_subledger_journals_do_not_conflict(): void
    {
        $sourceId = (string) Str::ulid();

        // 1. Create a candidate-origin journal entry (journal_candidate_id set)
        $candId = (string) Str::ulid();
        JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'source_module' => 'Inventory',
            'source_type' => 'InventoryTransaction',
            'source_id' => $sourceId,
            'journal_candidate_id' => $candId,
            'posting_event' => 'InventoryAdjustmentVariance',
            'status' => JournalStatusEnum::Draft,
        ]);

        // 2. Create a direct subledger entry with the same property, source module, type, id, but NULL candidate ID.
        // These should not conflict because uk_gl_je_direct_subledger ignores rows with journal_candidate_id IS NOT NULL.
        $direct = JournalEntry::create([
            'property_id' => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'source_module' => 'Inventory',
            'source_type' => 'InventoryTransaction',
            'source_id' => $sourceId,
            'journal_candidate_id' => null,
            'status' => JournalStatusEnum::Draft,
        ]);

        $this->assertNotEmpty($direct->id);
    }

    // -------------------------------------------------------------------------
    // Test 11: Closed FinancialPeriod is rejected by existing path
    // -------------------------------------------------------------------------
    public function test_closed_financial_period_is_rejected(): void
    {
        // Close the period we set up
        DB::table('gl_financial_periods')
            ->where('id', $this->financialPeriodId)
            ->update(['status' => 'Closed', 'closed_at' => now()]);

        $candidate = $this->makeApprovedCandidate();

        $this->expectException(\Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException::class);
        $this->expectExceptionMessageMatches('/is closed or closing/');

        $this->finalizationService->finalize($candidate->id);
    }

    // -------------------------------------------------------------------------
    // Test 12: Non-representable amount fails before creating final journal
    // -------------------------------------------------------------------------
    public function test_non_representable_amount_fails_without_creating_journal(): void
    {
        $candidate = $this->makeApprovedCandidate();
        DB::table('journal_candidate_lines')
            ->where('journal_candidate_id', $candidate->id)
            ->update(['amount' => '100.1234']);

        $candidate->refresh();

        try {
            $this->finalizationService->finalize($candidate->id);
            $this->fail('Finalization should have failed.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is not representable', $e->getMessage());
        }

        $this->assertCount(0, JournalEntry::where('journal_candidate_id', $candidate->id)->get());
    }
}
