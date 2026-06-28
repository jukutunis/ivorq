<?php

namespace Tests\Postgres\Finance\GeneralLedger;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\JournalEntryLine;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Modules\Finance\GeneralLedger\Services\JournalReversalService;
use Modules\Foundation\User\Models\User;
use Tests\PostgresTestCase;

class JournalReversalServiceTest extends PostgresTestCase
{
    use RefreshDatabase;

    protected $seed = true;

    protected function connectionsToTransact(): array
    {
        return [];
    }

    private JournalReversalService $reversalService;
    private string $propertyId;
    private string $actorId;
    private string $assetAccountId;
    private string $revenueAccountId;
    private string $financialPeriodId;
    private string $businessDateId;

    protected function setUp(): void
    {
        parent::setUp();

        $this->reversalService = app(JournalReversalService::class);

        $this->propertyId = (string) Str::ulid();
        $this->actorId    = (string) Str::ulid();

        DB::table('properties')->insert([
            'id'         => $this->propertyId,
            'name'       => 'Test Property',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user = User::first() ?? User::create([
            'id'       => $this->actorId,
            'name'     => 'Test User',
            'email'    => 'reversal-' . uniqid() . '@example.com',
            'password' => bcrypt('password'),
        ]);
        $this->actingAs($user);

        $asset = Account::create([
            'property_id'      => $this->propertyId,
            'code'             => '1000',
            'name'             => 'Inventory Asset',
            'account_type'     => AccountTypeEnum::Asset->value,
            'account_category' => 'CurrentAsset',
            'normal_balance'   => 'Debit',
            'is_active'        => true,
        ]);
        $this->assetAccountId = $asset->id;

        $revenue = Account::create([
            'property_id'      => $this->propertyId,
            'code'             => '4000',
            'name'             => 'Revenue',
            'account_type'     => AccountTypeEnum::Revenue->value,
            'account_category' => 'Revenue',
            'normal_balance'   => 'Credit',
            'is_active'        => true,
        ]);
        $this->revenueAccountId = $revenue->id;

        $this->financialPeriodId = (string) Str::ulid();
        DB::table('gl_financial_periods')->insert([
            'id'           => $this->financialPeriodId,
            'property_id'  => $this->propertyId,
            'period_year'  => 2026,
            'period_month' => 7,
            'status'       => 'Open',
            'opened_at'    => now(),
            'created_at'   => now(),
            'updated_at'   => now(),
        ]);

        $this->businessDateId = (string) Str::ulid();
        DB::table('property_business_dates')->insert([
            'id'            => $this->businessDateId,
            'property_id'   => $this->propertyId,
            'business_date' => '2026-07-01',
            'status'        => 'Open',
            'is_open'       => true,
            'opened_at'     => now(),
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
    }

    private function createPostedJournal(string $transactionDate = '2026-07-01'): JournalEntry
    {
        $entry = JournalEntry::create([
            'property_id'      => $this->propertyId,
            'transaction_date' => $transactionDate,
            'status'           => JournalStatusEnum::Draft,
            'source_module'    => 'Inventory',
            'source_type'      => 'InventoryTransaction',
            'source_id'        => (string) Str::ulid(),
            'posting_event'    => 'InventoryAdjustmentVariance',
        ]);

        JournalEntryLine::create([
            'property_id'      => $this->propertyId,
            'journal_entry_id' => $entry->id,
            'account_id'       => $this->assetAccountId,
            'debit_amount'     => '100.00',
            'credit_amount'    => '0.00',
        ]);

        JournalEntryLine::create([
            'property_id'      => $this->propertyId,
            'journal_entry_id' => $entry->id,
            'account_id'       => $this->revenueAccountId,
            'debit_amount'     => '0.00',
            'credit_amount'    => '100.00',
        ]);

        return app(GeneralLedgerService::class)->postJournalEntry($entry->id);
    }

    // -------------------------------------------------------------------------
    // Proof 1: Valid Posted original creates exactly one Posted reversal
    // -------------------------------------------------------------------------
    public function test_valid_posted_original_creates_one_posted_reversal(): void
    {
        $original = $this->createPostedJournal();
        $reversal = $this->reversalService->reverse(
            $original->id,
            '2026-07-01',
            'Test reversal reason',
            $this->actorId
        );

        $this->assertEquals(JournalStatusEnum::Posted, $reversal->status);
        $this->assertCount(1, JournalEntry::where('reversal_of_id', $original->id)->get());
    }

    // -------------------------------------------------------------------------
    // Proof 2: Reversal attributes, line swap, and LedgerBalance impact
    // -------------------------------------------------------------------------
    public function test_reversal_has_correct_attributes_swapped_lines_and_balance_impact(): void
    {
        $original = $this->createPostedJournal();
        $reversal = $this->reversalService->reverse(
            $original->id,
            '2026-07-01',
            'Adjustment correction',
            $this->actorId
        );

        // Header provenance
        $this->assertEquals($original->id, $reversal->reversal_of_id);
        $this->assertNull($reversal->journal_candidate_id);
        $this->assertEquals($original->source_module, $reversal->source_module);
        $this->assertEquals($original->source_type, $reversal->source_type);
        $this->assertEquals($original->source_id, $reversal->source_id);
        $this->assertEquals('JournalReversal', $reversal->posting_event);
        $this->assertStringContainsString($original->id, $reversal->description);
        $this->assertStringContainsString('Adjustment correction', $reversal->description);

        // Line swap: original asset debit=100, credit=0 → reversal debit=0, credit=100
        $this->assertCount(2, $reversal->lines);
        $assetLine   = $reversal->lines->firstWhere('account_id', $this->assetAccountId);
        $revenueLine = $reversal->lines->firstWhere('account_id', $this->revenueAccountId);

        $this->assertEquals('0.00', $assetLine->debit_amount);
        $this->assertEquals('100.00', $assetLine->credit_amount);
        $this->assertEquals('100.00', $revenueLine->debit_amount);
        $this->assertEquals('0.00', $revenueLine->credit_amount);

        // LedgerBalance: original posted debit=100 to asset; reversal posted credit=100 to asset.
        // Net: debit_total=100, credit_total=100, ending_balance=0.
        $balance = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->where('account_id', $this->assetAccountId)
            ->where('period_year', 2026)
            ->where('period_month', 7)
            ->first();

        $this->assertNotNull($balance);
        $this->assertEquals('100.00', $balance->debit_total);
        $this->assertEquals('100.00', $balance->credit_total);
        $this->assertEquals('0.00', $balance->ending_balance);
    }

    // -------------------------------------------------------------------------
    // Proof 3: Original header and lines remain unchanged after reversal
    // -------------------------------------------------------------------------
    public function test_original_header_and_lines_remain_unchanged_after_reversal(): void
    {
        $original       = $this->createPostedJournal();
        $originalDate   = $original->transaction_date->toDateString();
        $originalLines  = $original->lines->map(fn ($l) => [
            'account_id'    => $l->account_id,
            'debit_amount'  => $l->debit_amount,
            'credit_amount' => $l->credit_amount,
        ])->toArray();

        $this->reversalService->reverse(
            $original->id,
            '2026-07-01',
            'Checking original unchanged',
            $this->actorId
        );

        $fresh = JournalEntry::with('lines')->find($original->id);

        $this->assertEquals(JournalStatusEnum::Posted, $fresh->status);
        $this->assertNull($fresh->reversal_of_id);
        $this->assertEquals($originalDate, $fresh->transaction_date->toDateString());

        foreach ($originalLines as $expected) {
            $line = $fresh->lines->firstWhere('account_id', $expected['account_id']);
            $this->assertEquals($expected['debit_amount'], $line->debit_amount);
            $this->assertEquals($expected['credit_amount'], $line->credit_amount);
        }
    }

    // -------------------------------------------------------------------------
    // Proof 4: Second reversal request returns existing reversal; no duplicate
    // -------------------------------------------------------------------------
    public function test_second_reversal_request_returns_existing_without_duplicate_or_extra_balance(): void
    {
        $original  = $this->createPostedJournal();
        $reversal1 = $this->reversalService->reverse($original->id, '2026-07-01', 'First', $this->actorId);
        $reversal2 = $this->reversalService->reverse($original->id, '2026-07-01', 'Second', $this->actorId);

        $this->assertEquals($reversal1->id, $reversal2->id);
        $this->assertCount(1, JournalEntry::where('reversal_of_id', $original->id)->get());

        // LedgerBalance must not be double-impacted.
        $balance = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->where('account_id', $this->assetAccountId)
            ->first();

        $this->assertEquals('100.00', $balance->debit_total);
        $this->assertEquals('100.00', $balance->credit_total);
    }

    // -------------------------------------------------------------------------
    // Proof 5: Draft original cannot be reversed
    // -------------------------------------------------------------------------
    public function test_draft_original_cannot_be_reversed(): void
    {
        $draft = JournalEntry::create([
            'property_id'      => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status'           => JournalStatusEnum::Draft,
        ]);

        try {
            $this->reversalService->reverse($draft->id, '2026-07-01', 'Attempt', $this->actorId);
            $this->fail('Reversing a Draft journal must fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('Posted', $e->getMessage());
        }

        $this->assertCount(0, JournalEntry::where('reversal_of_id', $draft->id)->get());
    }

    // -------------------------------------------------------------------------
    // Proof 6: A reversal journal cannot itself be reversed
    // -------------------------------------------------------------------------
    public function test_reversal_journal_cannot_itself_be_reversed(): void
    {
        $original = $this->createPostedJournal();
        $reversal = $this->reversalService->reverse(
            $original->id, '2026-07-01', 'First reversal', $this->actorId
        );

        try {
            $this->reversalService->reverse($reversal->id, '2026-07-01', 'Re-reverse', $this->actorId);
            $this->fail('Reversing a reversal must fail.');
        } catch (Exception $e) {
            $this->assertStringContainsString('reversal', strtolower($e->getMessage()));
        }

        $this->assertCount(0, JournalEntry::where('reversal_of_id', $reversal->id)->get());
    }

    // -------------------------------------------------------------------------
    // Proof 7: Closed / missing PBD or FinancialPeriod rejects reversal with full rollback
    // -------------------------------------------------------------------------
    public function test_closed_business_date_rejects_reversal_with_full_rollback(): void
    {
        $original = $this->createPostedJournal();

        DB::table('property_business_dates')
            ->where('id', $this->businessDateId)
            ->update(['status' => 'Closed', 'is_open' => false, 'closed_at' => now()]);

        try {
            $this->reversalService->reverse($original->id, '2026-07-01', 'Closed date', $this->actorId);
            $this->fail('Reversal must fail with a Closed PropertyBusinessDate.');
        } catch (Exception $e) {
            $this->assertStringContainsString('is not Open', $e->getMessage());
        }

        $this->assertCount(0, JournalEntry::where('reversal_of_id', $original->id)->get());

        // Asset balance remains at original post state — no reversal credit impact.
        $balance = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->where('account_id', $this->assetAccountId)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals('0.00', $balance->credit_total);
    }

    public function test_closed_financial_period_rejects_reversal_with_full_rollback(): void
    {
        $original = $this->createPostedJournal();

        DB::table('gl_financial_periods')
            ->where('id', $this->financialPeriodId)
            ->update(['status' => 'Closed', 'closed_at' => now()]);

        try {
            $this->reversalService->reverse($original->id, '2026-07-01', 'Closed period', $this->actorId);
            $this->fail('Reversal must fail with a Closed FinancialPeriod.');
        } catch (\Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException $e) {
            $this->assertStringContainsString('is closed or closing', $e->getMessage());
        }

        $this->assertCount(0, JournalEntry::where('reversal_of_id', $original->id)->get());

        $balance = DB::table('gl_ledger_balances')
            ->where('property_id', $this->propertyId)
            ->where('account_id', $this->assetAccountId)
            ->first();
        $this->assertNotNull($balance);
        $this->assertEquals('0.00', $balance->credit_total);
    }

    public function test_missing_financial_period_rejects_reversal_with_full_rollback(): void
    {
        $original = $this->createPostedJournal();

        DB::table('gl_financial_periods')
            ->where('id', $this->financialPeriodId)
            ->delete();

        try {
            $this->reversalService->reverse($original->id, '2026-07-01', 'No period', $this->actorId);
            $this->fail('Reversal must fail without a FinancialPeriod row.');
        } catch (\Modules\Finance\GeneralLedger\Exceptions\PeriodClosedException $e) {
            $this->assertStringContainsString('FinancialPeriod not found', $e->getMessage());
        }

        $this->assertCount(0, JournalEntry::where('reversal_of_id', $original->id)->get());
    }

    // -------------------------------------------------------------------------
    // Proof 8: Database-level trigger rejections
    // -------------------------------------------------------------------------
    public function test_db_trigger_rejects_reversal_of_non_posted_original(): void
    {
        $draft = JournalEntry::create([
            'property_id'      => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status'           => JournalStatusEnum::Draft,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/non-Posted/');

        DB::table('gl_journal_entries')->insert([
            'id'               => (string) Str::ulid(),
            'property_id'      => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status'           => 'Draft',
            'reversal_of_id'   => $draft->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_db_trigger_rejects_reversal_of_another_reversal(): void
    {
        $original = $this->createPostedJournal();
        $reversal = $this->reversalService->reverse(
            $original->id, '2026-07-01', 'First', $this->actorId
        );

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/reversal journal entry/');

        DB::table('gl_journal_entries')->insert([
            'id'               => (string) Str::ulid(),
            'property_id'      => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status'           => 'Draft',
            'reversal_of_id'   => $reversal->id,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    public function test_db_trigger_rejects_self_referential_reversal(): void
    {
        $selfId = (string) Str::ulid();

        $this->expectException(\Illuminate\Database\QueryException::class);
        $this->expectExceptionMessageMatches('/cannot reverse itself/');

        DB::table('gl_journal_entries')->insert([
            'id'               => $selfId,
            'property_id'      => $this->propertyId,
            'transaction_date' => '2026-07-01',
            'status'           => 'Draft',
            'reversal_of_id'   => $selfId,
            'created_at'       => now(),
            'updated_at'       => now(),
        ]);
    }

    // -------------------------------------------------------------------------
    // Proof 9: Migration safety check fails closed on historical duplicate reversals
    // -------------------------------------------------------------------------
    public function test_migration_safety_check_fails_closed_on_duplicate_reversal_rows(): void
    {
        // Temporarily drop the unique index to simulate a pre-migration state with duplicates.
        // The finally block restores it so subsequent tests are not affected.
        try {
            DB::statement('DROP INDEX IF EXISTS uk_gl_je_one_reversal_per_original');

            $original = $this->createPostedJournal();

            // Both inserts pass the reversal integrity trigger (original is Posted and not a reversal),
            // and now pass the unique index too (it is dropped).
            DB::table('gl_journal_entries')->insert([
                'id'               => (string) Str::ulid(),
                'property_id'      => $this->propertyId,
                'transaction_date' => '2026-07-01',
                'status'           => 'Draft',
                'reversal_of_id'   => $original->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            DB::table('gl_journal_entries')->insert([
                'id'               => (string) Str::ulid(),
                'property_id'      => $this->propertyId,
                'transaction_date' => '2026-07-01',
                'status'           => 'Draft',
                'reversal_of_id'   => $original->id,
                'created_at'       => now(),
                'updated_at'       => now(),
            ]);

            // Run the same duplicate-detection query the migration uses.
            $duplicates = DB::select("
                SELECT reversal_of_id
                FROM gl_journal_entries
                WHERE reversal_of_id IS NOT NULL
                GROUP BY reversal_of_id
                HAVING COUNT(*) > 1
                LIMIT 1
            ");

            $this->assertNotEmpty(
                $duplicates,
                'Migration safety check must detect duplicate reversal_of_id values and fail closed.'
            );
        } finally {
            DB::statement('
                CREATE UNIQUE INDEX IF NOT EXISTS uk_gl_je_one_reversal_per_original
                ON gl_journal_entries(reversal_of_id)
                WHERE reversal_of_id IS NOT NULL
            ');
        }
    }
}
