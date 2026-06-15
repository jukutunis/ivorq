<?php

namespace Tests\Feature\Finance\Banking;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Banking\Services\VarianceJournalingEngine;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Models\OperationalIdentityMapping;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Finance\GeneralLedger\Enums\AccountTypeEnum;

class VarianceJournalingEngineTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected VarianceJournalingEngine $engine;
    protected $property;
    protected $bankAccount;
    protected $statement;
    protected $session;
    protected $userId = 'ULID_USER_1';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->engine = app(VarianceJournalingEngine::class);

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        
        app(\Shared\Services\CurrentPropertyService::class)->setId($this->property->id);

        // create accounts and mappings
        $this->setupMappings();

        $this->bankAccount = BankAccount::create([
            'property_id' => $this->property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $this->statement = BankStatement::create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2023-01-10',
            'opening_balance' => 0,
            'closing_balance' => 0,
        ]);

        $this->session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Completed,
            'created_by' => $this->userId,
            'statement_date_start' => '2023-01-01',
            'statement_date_end' => '2023-01-31',
        ]);
    }

    protected function setupMappings()
    {
        $feeAccount = Account::create(['property_id' => $this->property->id, 'code' => '6001', 'name' => 'Bank Fees', 'account_type' => AccountTypeEnum::Expense->value, 'account_category' => 'Expense', 'normal_balance' => 'Debit', 'is_active' => true]);
        $varAccount = Account::create(['property_id' => $this->property->id, 'code' => '6002', 'name' => 'Payment Variance', 'account_type' => AccountTypeEnum::Expense->value, 'account_category' => 'Expense', 'normal_balance' => 'Debit', 'is_active' => true]);
        $suspenseAccount = Account::create(['property_id' => $this->property->id, 'code' => '2001', 'name' => 'Suspense', 'account_type' => AccountTypeEnum::Liability->value, 'account_category' => 'CurrentLiability', 'normal_balance' => 'Credit', 'is_active' => true]);
        $adjAccount = Account::create(['property_id' => $this->property->id, 'code' => '6003', 'name' => 'Adjustments', 'account_type' => AccountTypeEnum::Expense->value, 'account_category' => 'Expense', 'normal_balance' => 'Debit', 'is_active' => true]);

        OperationalIdentityMapping::create(['property_id' => $this->property->id, 'operational_identity' => OperationalIdentityEnum::BANK_FEE->value, 'account_id' => $feeAccount->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        OperationalIdentityMapping::create(['property_id' => $this->property->id, 'operational_identity' => OperationalIdentityEnum::PAYMENT_VARIANCE->value, 'account_id' => $varAccount->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        OperationalIdentityMapping::create(['property_id' => $this->property->id, 'operational_identity' => OperationalIdentityEnum::UNMATCHED_BANK_LINE->value, 'account_id' => $suspenseAccount->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
        OperationalIdentityMapping::create(['property_id' => $this->property->id, 'operational_identity' => OperationalIdentityEnum::MANUAL_ADJUSTMENT->value, 'account_id' => $adjAccount->id, 'effective_from' => '2020-01-01', 'is_active' => true]);
    }

    protected function createBankLine(float $amount, bool $isReconciled = false): BankStatementLine
    {
        return BankStatementLine::create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2023-01-10',
            'description' => 'Test',
            'amount' => $amount,
            'is_reconciled' => $isReconciled,
        ]);
    }

    protected function createPayment(float $amount, float $bankFee = 0.0): VendorPayment
    {
        $category = \Modules\Operations\Purchasing\Models\VendorCategory::firstOrCreate(
            ['property_id' => $this->property->id, 'category_code' => 'CAT01'],
            ['name' => 'Test Category']
        );

        $vendor = \Modules\Operations\Purchasing\Models\Vendor::firstOrCreate(
            ['property_id' => $this->property->id, 'vendor_code' => 'V001'],
            ['vendor_category_id' => $category->id, 'name' => 'Test Vendor']
        );

        return VendorPayment::create([
            'property_id' => $this->property->id,
            'vendor_id' => $vendor->id,
            'bank_account_id' => $this->bankAccount->id,
            'total_amount' => $amount,
            'bank_fee_amount' => $bankFee,
            'payment_date' => '2023-01-10',
            'status' => VendorPaymentStatusEnum::Executed->value,
            'payment_number' => 'PAY-' . rand(1000, 9999),
        ]);
    }

    public function test_session_must_be_completed()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'status' => ReconciliationSessionStatusEnum::InProgress,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Variance Journaling Engine can only process Completed sessions.');

        $this->engine->processSession($session);
    }

    public function test_generates_bank_fee_candidate()
    {
        $payment = $this->createPayment(1000.0, 15.0); // 15 fee
        $line = $this->createBankLine(-1015.0, true);
        
        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => $line->id,
            'matchable_type' => VendorPayment::class,
            'matchable_id' => $payment->id,
            'amount_matched' => 1015.0, // matched with the fee included
            'matchable_amount' => 1000.0,
            'statement_amount' => 1015.0,
            'bank_account_balance_before' => 0.0,
            'bank_account_balance_after' => 1015.0,
            'match_method' => 'EXACT',
            'matched_by' => $this->userId,
        ]);

        $this->engine->processSession($this->session);

        $this->assertDatabaseHas('journal_candidates', [
            'source_type' => VendorPayment::class,
            'source_id' => $payment->id,
            'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
        ]);

        $candidate = JournalCandidate::where('source_type', VendorPayment::class)->first();
        $this->assertEquals(OperationalIdentityEnum::BANK_FEE->value, $candidate->metadata['variance_type']);
        $this->assertEquals(15.0, $candidate->metadata['variance_amount']);
    }

    public function test_generates_payment_variance()
    {
        $payment = $this->createPayment(1000.0);
        $line = $this->createBankLine(-990.0, true);
        
        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => $line->id,
            'matchable_type' => VendorPayment::class,
            'matchable_id' => $payment->id,
            'amount_matched' => 990.0, // 10 short
            'matchable_amount' => 1000.0,
            'statement_amount' => 990.0,
            'bank_account_balance_before' => 0.0,
            'bank_account_balance_after' => 990.0,
            'match_method' => 'EXACT',
            'matched_by' => $this->userId,
        ]);

        $this->engine->processSession($this->session);

        $candidate = JournalCandidate::where('source_type', ReconciliationSession::class)->first();
        
        $this->assertNotNull($candidate);
        $this->assertEquals(OperationalIdentityEnum::PAYMENT_VARIANCE->value, $candidate->metadata['variance_type']);
        $this->assertEquals(10.0, $candidate->metadata['variance_amount']);
    }

    public function test_unmatched_bank_lines_suspense()
    {
        $line = $this->createBankLine(-500.0, false);

        $this->engine->processSession($this->session);

        $candidate = JournalCandidate::where('source_type', BankStatementLine::class)->first();
        $this->assertNotNull($candidate);
        $this->assertEquals(OperationalIdentityEnum::UNMATCHED_BANK_LINE->value, $candidate->metadata['variance_type']);
        $this->assertEquals(500.0, $candidate->metadata['variance_amount']);
    }

    public function test_manual_adjustment()
    {
        $line = $this->createBankLine(-500.0, true);
        
        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => $line->id,
            'matchable_type' => BankStatementLine::class, // Just a dummy matchable
            'matchable_id' => $line->id,
            'amount_matched' => 500.0,
            'matchable_amount' => 400.0,
            'statement_amount' => 500.0,
            'bank_account_balance_before' => 0.0,
            'bank_account_balance_after' => 500.0,
            'match_method' => 'MANUAL_OVERRIDE',
            'override_reason' => 'Wrote off missing amount',
            'matched_by' => $this->userId,
        ]);

        $this->engine->processSession($this->session);

        $candidate = JournalCandidate::where('source_type', ReconciliationMatch::class)->first();
        $this->assertNotNull($candidate);
        $this->assertEquals(OperationalIdentityEnum::MANUAL_ADJUSTMENT->value, $candidate->metadata['variance_type']);
        $this->assertEquals(100.0, $candidate->metadata['variance_amount']);
    }

    public function test_missing_override_reason_throws()
    {
        $line = $this->createBankLine(-500.0, true);
        
        $match = ReconciliationMatch::create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => $line->id,
            'matchable_type' => BankStatementLine::class,
            'matchable_id' => $line->id,
            'amount_matched' => 500.0,
            'matchable_amount' => 400.0,
            'statement_amount' => 500.0,
            'bank_account_balance_before' => 0.0,
            'bank_account_balance_after' => 500.0,
            'match_method' => 'MANUAL_OVERRIDE',
            'override_reason' => null, // MISSING
            'matched_by' => $this->userId,
        ]);

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/missing override_reason/');

        $this->engine->processSession($this->session);
    }
}
