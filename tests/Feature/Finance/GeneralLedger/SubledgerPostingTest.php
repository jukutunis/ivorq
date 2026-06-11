<?php

namespace Tests\Feature\Finance\GeneralLedger;

use Exception;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Models\PostingProfile;
use Modules\Finance\GeneralLedger\Models\PostingRule;
use Modules\Finance\GeneralLedger\Models\PostingLog;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;
use Modules\Finance\GeneralLedger\Enums\NormalBalanceEnum;
use Modules\Finance\GeneralLedger\Enums\PostingEventEnum;
use Modules\Finance\GeneralLedger\Enums\AccountRoleEnum;
use Modules\Finance\GeneralLedger\Enums\PostingLogStatusEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Services\GeneralLedgerService;
use Modules\Finance\GeneralLedger\Services\SubledgerPostingService;

class SubledgerPostingTest extends TestCase
{
    use RefreshDatabase;

    protected SubledgerPostingService $postingService;
    protected string $propertyId;

    protected function setUp(): void
    {
        parent::setUp();
        $this->postingService = new SubledgerPostingService(new GeneralLedgerService());
        $this->propertyId = (string) Str::ulid();
    }

    protected function createAccount(string $code, NormalBalanceEnum $balance, AccountTypeEnum $type): Account
    {
        return Account::create([
            'property_id' => $this->propertyId,
            'code' => $code,
            'name' => "Account $code",
            'normal_balance' => $balance,
            'account_type' => $type,
        ]);
    }

    public function test_can_create_posting_profile()
    {
        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
            'description' => 'AP Invoice Profile',
        ]);

        $this->assertDatabaseHas('gl_posting_profiles', [
            'id' => $profile->id,
            'module' => 'Payables',
        ]);
    }

    public function test_can_create_posting_rule()
    {
        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $account = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $rule = PostingRule::create([
            'property_id' => $this->propertyId,
            'posting_profile_id' => $profile->id,
            'account_role' => AccountRoleEnum::AP_Liability->value,
            'account_id' => $account->id,
        ]);

        $this->assertDatabaseHas('gl_posting_rules', [
            'id' => $rule->id,
            'account_role' => AccountRoleEnum::AP_Liability->value,
        ]);
    }

    public function test_account_payable_posts_to_gl()
    {
        $expenseAccount = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Expense_Account->value, 'account_id' => $expenseAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);

        $apId = (string) Str::ulid();
        
        $journal = $this->postingService->postAccountPayable(
            $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
        );

        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::Posted, $journal->status);
        $this->assertEquals('Payables', $journal->source_module);
        $this->assertEquals('AccountPayable', $journal->source_type);
        $this->assertEquals($apId, $journal->source_id);

        $this->assertEquals(2, $journal->lines()->count());
    }

    public function test_payment_voucher_posts_to_gl()
    {
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);
        $cashAccount = $this->createAccount('1000', NormalBalanceEnum::Debit, AccountTypeEnum::Asset);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::PaymentVoucher->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Cash_Account->value, 'account_id' => $cashAccount->id]);

        $pvId = (string) Str::ulid();
        
        $journal = $this->postingService->postPaymentVoucher(
            $this->propertyId, $pvId, 1000.00, '2026-06-11', 'PV-001', 'Payment'
        );

        $this->assertNotNull($journal);
        $this->assertEquals(JournalStatusEnum::Posted, $journal->status);
        $this->assertEquals('PaymentVoucher', $journal->source_type);
        $this->assertEquals($pvId, $journal->source_id);
    }

    public function test_duplicate_posting_is_blocked()
    {
        $expenseAccount = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Expense_Account->value, 'account_id' => $expenseAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);

        $apId = (string) Str::ulid();
        
        $this->postingService->postAccountPayable(
            $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
        );

        $this->expectException(Exception::class);
        $this->expectExceptionMessage('Duplicate posting blocked.');

        $this->postingService->postAccountPayable(
            $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
        );
    }

    public function test_missing_rule_creates_failed_posting_log()
    {
        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $apId = (string) Str::ulid();
        
        try {
            $this->postingService->postAccountPayable(
                $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
            );
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertEquals('Missing posting rules for required roles.', $e->getMessage());
        }

        $log = PostingLog::where('source_id', $apId)->first();
        $this->assertNotNull($log);
        $this->assertEquals(PostingLogStatusEnum::Failed, $log->status);
    }

    public function test_cross_property_posting_is_blocked()
    {
        $propertyId2 = (string) Str::ulid();
        $expenseAccount = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Expense_Account->value, 'account_id' => $expenseAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);

        $apId = (string) Str::ulid();
        
        // Call with propertyId2 but rules/accounts are propertyId1
        try {
            $this->postingService->postAccountPayable(
                $propertyId2, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
            );
            $this->fail('Expected exception was not thrown');
        } catch (Exception $e) {
            $this->assertStringContainsString('Active posting profile not found', $e->getMessage());
        }
    }

    public function test_posting_log_created_on_success()
    {
        $expenseAccount = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Expense_Account->value, 'account_id' => $expenseAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);

        $apId = (string) Str::ulid();
        
        $journal = $this->postingService->postAccountPayable(
            $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
        );

        $log = PostingLog::where('source_id', $apId)->first();
        $this->assertNotNull($log);
        $this->assertEquals(PostingLogStatusEnum::Success, $log->status);
        $this->assertEquals($journal->id, $log->journal_entry_id);
    }

    public function test_posting_creates_balanced_journal()
    {
        $expenseAccount = $this->createAccount('5000', NormalBalanceEnum::Debit, AccountTypeEnum::Expense);
        $liabilityAccount = $this->createAccount('2000', NormalBalanceEnum::Credit, AccountTypeEnum::Liability);

        $profile = PostingProfile::create([
            'property_id' => $this->propertyId,
            'module' => 'Payables',
            'event' => PostingEventEnum::AccountPayable->value,
        ]);

        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::Expense_Account->value, 'account_id' => $expenseAccount->id]);
        $profile->rules()->create(['property_id' => $this->propertyId, 'account_role' => AccountRoleEnum::AP_Liability->value, 'account_id' => $liabilityAccount->id]);

        $apId = (string) Str::ulid();
        
        $journal = $this->postingService->postAccountPayable(
            $this->propertyId, $apId, 1500.00, '2026-06-11', 'INV-001', 'Test Invoice'
        );

        $debitTotal = $journal->lines()->sum('debit_amount');
        $creditTotal = $journal->lines()->sum('credit_amount');

        $this->assertEquals(1500.00, $debitTotal);
        $this->assertEquals(1500.00, $creditTotal);
        $this->assertEquals($debitTotal, $creditTotal);
    }
}
