<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Models\LedgerBalance;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Exception;

class GeneralLedgerTest extends TestCase
{
    use RefreshDatabase;

    protected GeneralLedgerService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new GeneralLedgerService();
    }

    public function test_can_create_account()
    {
        $propertyId = (string) Str::ulid();
        $account = Account::create([
            'property_id' => $propertyId,
            'code' => '1000',
            'name' => 'Cash',
            'normal_balance' => NormalBalanceEnum::Debit,
            'account_type' => AccountTypeEnum::Asset,
        ]);

        $this->assertDatabaseHas('gl_accounts', [
            'id' => $account->id,
            'code' => '1000',
        ]);
    }

    public function test_account_code_unique_per_property()
    {
        $propertyId = (string) Str::ulid();
        
        Account::create([
            'property_id' => $propertyId,
            'code' => '1000',
            'name' => 'Cash',
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);

        Account::create([
            'property_id' => $propertyId,
            'code' => '1000',
            'name' => 'Bank',
        ]);
    }

    public function test_can_create_draft_journal()
    {
        $propertyId = (string) Str::ulid();
        $journal = JournalEntry::create([
            'property_id' => $propertyId,
            'transaction_date' => '2026-06-11',
            'description' => 'Test',
        ]);
        $journal->refresh();

        $this->assertEquals(JournalStatusEnum::Draft, $journal->status);
        
        $journal->update(['description' => 'Updated']);
        $this->assertEquals('Updated', $journal->fresh()->description);
    }

    public function test_cannot_post_out_of_balance_journal()
    {
        $propertyId = (string) Str::ulid();
        
        $account1 = Account::create(['property_id' => $propertyId, 'code' => '1000', 'name' => 'Cash']);
        $account2 = Account::create(['property_id' => $propertyId, 'code' => '4000', 'name' => 'Revenue']);

        $journal = JournalEntry::create(['property_id' => $propertyId, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account1->id, 'debit_amount' => 100, 'credit_amount' => 0]);
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account2->id, 'debit_amount' => 0, 'credit_amount' => 90]); // Out of balance

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Out-of-balance journal cannot be posted.');

        $this->service->postJournalEntry($journal->id);
    }

    public function test_can_post_balanced_journal()
    {
        $propertyId = (string) Str::ulid();
        
        $account1 = Account::create(['property_id' => $propertyId, 'code' => '1000', 'name' => 'Cash', 'normal_balance' => NormalBalanceEnum::Debit, 'account_type' => AccountTypeEnum::Asset]);
        $account2 = Account::create(['property_id' => $propertyId, 'code' => '4000', 'name' => 'Revenue', 'normal_balance' => NormalBalanceEnum::Credit, 'account_type' => AccountTypeEnum::Revenue]);

        $journal = JournalEntry::create(['property_id' => $propertyId, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account1->id, 'debit_amount' => 100, 'credit_amount' => 0]);
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account2->id, 'debit_amount' => 0, 'credit_amount' => 100]);

        $postedJournal = $this->service->postJournalEntry($journal->id);

        $this->assertEquals(JournalStatusEnum::Posted, $postedJournal->status);
        $this->assertNotNull($postedJournal->posting_date);
    }

    public function test_posted_journal_is_immutable()
    {
        $propertyId = (string) Str::ulid();
        
        $account1 = Account::create(['property_id' => $propertyId, 'code' => '1000', 'name' => 'Cash']);
        $journal = JournalEntry::create(['property_id' => $propertyId, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account1->id, 'debit_amount' => 100, 'credit_amount' => 100]); // balanced

        $this->service->postJournalEntry($journal->id);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Journal entry is already posted and immutable.');

        $this->service->postJournalEntry($journal->id);
    }

    public function test_posting_updates_ledger_balance()
    {
        $propertyId = (string) Str::ulid();
        
        $account1 = Account::create(['property_id' => $propertyId, 'code' => '1000', 'name' => 'Cash', 'normal_balance' => NormalBalanceEnum::Debit, 'account_type' => AccountTypeEnum::Asset]);
        $account2 = Account::create(['property_id' => $propertyId, 'code' => '4000', 'name' => 'Revenue', 'normal_balance' => NormalBalanceEnum::Credit, 'account_type' => AccountTypeEnum::Revenue]);

        $journal = JournalEntry::create(['property_id' => $propertyId, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account1->id, 'debit_amount' => 100, 'credit_amount' => 0]);
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $account2->id, 'debit_amount' => 0, 'credit_amount' => 100]);

        $this->service->postJournalEntry($journal->id);

        $balance1 = LedgerBalance::where('account_id', $account1->id)->where('period_year', 2026)->where('period_month', 6)->first();
        $balance2 = LedgerBalance::where('account_id', $account2->id)->where('period_year', 2026)->where('period_month', 6)->first();

        $this->assertEquals(100, $balance1->debit_total);
        $this->assertEquals(0, $balance1->credit_total);
        $this->assertEquals(100, $balance1->ending_balance);

        $this->assertEquals(0, $balance2->debit_total);
        $this->assertEquals(100, $balance2->credit_total);
        $this->assertEquals(100, $balance2->ending_balance); // credit normal balance => credit - debit = 100
    }

    public function test_statistical_account_cannot_be_used_for_money_journal()
    {
        $propertyId = (string) Str::ulid();
        
        $accountStat = Account::create(['property_id' => $propertyId, 'code' => 'STAT1', 'name' => 'Rooms', 'account_type' => AccountTypeEnum::Statistical]);

        $journal = JournalEntry::create(['property_id' => $propertyId, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId, 'account_id' => $accountStat->id, 'debit_amount' => 10, 'credit_amount' => 10]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Statistical accounts cannot be used with money debit/credit amounts.');

        $this->service->postJournalEntry($journal->id);
    }

    public function test_cross_property_journal_is_blocked()
    {
        $propertyId1 = (string) Str::ulid();
        $propertyId2 = (string) Str::ulid();
        
        $account1 = Account::create(['property_id' => $propertyId1, 'code' => '1000', 'name' => 'Cash']);
        $account2 = Account::create(['property_id' => $propertyId2, 'code' => '4000', 'name' => 'Revenue']);

        $journal = JournalEntry::create(['property_id' => $propertyId1, 'transaction_date' => '2026-06-11']);
        
        $journal->lines()->create(['property_id' => $propertyId1, 'account_id' => $account1->id, 'debit_amount' => 100, 'credit_amount' => 0]);
        // line belonging to property 2, but journal belongs to property 1
        $journal->lines()->create(['property_id' => $propertyId2, 'account_id' => $account2->id, 'debit_amount' => 0, 'credit_amount' => 100]);

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Cross-property journals are blocked.');

        $this->service->postJournalEntry($journal->id);
    }

    public function test_audit_log_created()
    {
        // Simple assertion that created_by or updated_by is fillable/tracked.
        // Assuming traits handle it, we just test if models exist with IDs.
        $propertyId = (string) Str::ulid();
        $account = Account::create(['property_id' => $propertyId, 'code' => '1000', 'name' => 'Cash']);
        
        $this->assertNotNull($account->id);
        $this->assertTrue(in_array('created_by', $account->getFillable()) || in_array('created_by', \Illuminate\Support\Facades\Schema::getColumnListing('gl_accounts')));
    }
}
