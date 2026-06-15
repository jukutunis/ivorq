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
use Modules\Finance\Banking\Services\ReconciliationCommitService;
use Modules\Finance\Banking\Exceptions\ReconciliationCommitException;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;

class ReconciliationCommitServiceTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected ReconciliationCommitService $service;
    protected $property;
    protected $bankAccount;
    protected $statement;
    protected $session;
    protected $userId = 'ULID_USER_1';

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->service = app(ReconciliationCommitService::class);

        $company = $this->createCompany();
        $this->property = $this->createProperty($company);

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

        $this->session = ReconciliationSession::create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => 'InProgress',
            'started_at' => now(),
            'started_by' => $this->userId,
            'statement_date_start' => '2023-01-01',
            'statement_date_end' => '2023-01-31',
        ]);
    }

    protected function createBankLine(float $amount): BankStatementLine
    {
        return BankStatementLine::create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2023-01-10',
            'description' => 'Test',
            'amount' => $amount,
            'is_reconciled' => false,
        ]);
    }

    protected function createPayment(float $amount): VendorPayment
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
            'payment_date' => '2023-01-10',
            'status' => VendorPaymentStatusEnum::Executed->value,
            'payment_number' => 'PAY-' . rand(1000, 9999),
        ]);
    }

    public function test_manual_override_creates_match_and_updates_status()
    {
        $line = $this->createBankLine(-1000.0);
        $payment = $this->createPayment(1000.0);

        $match = $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1000.0,
            'MANUAL_OVERRIDE',
            $this->userId,
            'Forced match by accountant'
        );

        $this->assertEquals('MANUAL_OVERRIDE', $match->match_method);
        $this->assertEquals($this->userId, $match->matched_by);
        $this->assertEquals('Forced match by accountant', $match->override_reason);

        // Verify states updated
        $this->assertTrue($line->fresh()->is_reconciled);
        $this->assertEquals(VendorPaymentStatusEnum::Reconciled, $payment->fresh()->status);
    }

    public function test_split_matching()
    {
        $line = $this->createBankLine(-1500.0);
        $payment1 = $this->createPayment(1000.0);
        $payment2 = $this->createPayment(500.0);

        $matches = $this->service->commitSplit(
            $this->session,
            $line->id,
            [
                ['type' => VendorPayment::class, 'id' => $payment1->id, 'amount' => 1000.0],
                ['type' => VendorPayment::class, 'id' => $payment2->id, 'amount' => 500.0],
            ],
            $this->userId
        );

        $this->assertCount(2, $matches);
        $this->assertTrue($line->fresh()->is_reconciled);
        $this->assertEquals(VendorPaymentStatusEnum::Reconciled, $payment1->fresh()->status);
        $this->assertEquals(VendorPaymentStatusEnum::Reconciled, $payment2->fresh()->status);
        $this->assertEquals('SPLIT', $matches[0]->match_method);
    }

    public function test_merge_matching()
    {
        $line1 = $this->createBankLine(-1000.0);
        $line2 = $this->createBankLine(-500.0);
        $payment = $this->createPayment(1500.0);

        $matches = $this->service->commitMerge(
            $this->session,
            [
                ['id' => $line1->id, 'amount' => 1000.0],
                ['id' => $line2->id, 'amount' => 500.0],
            ],
            VendorPayment::class,
            $payment->id,
            $this->userId
        );

        $this->assertCount(2, $matches);
        $this->assertTrue($line1->fresh()->is_reconciled);
        $this->assertTrue($line2->fresh()->is_reconciled);
        $this->assertEquals(VendorPaymentStatusEnum::Reconciled, $payment->fresh()->status);
        $this->assertEquals('MERGE', $matches[0]->match_method);
    }

    public function test_over_allocation_protection_for_bank_line()
    {
        $line = $this->createBankLine(-1000.0);
        $payment = $this->createPayment(1500.0); // Payment is larger than line

        $this->expectException(ReconciliationCommitException::class);
        $this->expectExceptionMessageMatches('/Over Allocation Error on BankStatementLine/');

        // Try to match 1500 against a 1000 bank line
        $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1500.0,
            'MANUAL_OVERRIDE',
            $this->userId
        );
    }

    public function test_over_allocation_protection_for_matchable()
    {
        $line = $this->createBankLine(-1500.0); // Line is larger
        $payment = $this->createPayment(1000.0);

        $this->expectException(ReconciliationCommitException::class);
        $this->expectExceptionMessageMatches('/Over Allocation Error on Matchable/');

        // Try to match 1500 against a 1000 payment
        $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1500.0,
            'MANUAL_OVERRIDE',
            $this->userId
        );
    }

    public function test_immutability_protection()
    {
        $line = $this->createBankLine(-1000.0);
        // Create an ALREADY reconciled payment
        $payment = $this->createPayment(1000.0);
        $payment->update(['status' => VendorPaymentStatusEnum::Reconciled->value]);

        $this->expectException(ReconciliationCommitException::class);
        $this->expectExceptionMessageMatches('/Immutability Error/');

        $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1000.0,
            'MANUAL_OVERRIDE',
            $this->userId
        );
    }

    public function test_duplicate_match_rejection()
    {
        $line = $this->createBankLine(-1000.0);
        $payment = $this->createPayment(1000.0);

        // First match succeeds
        $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1000.0,
            'MANUAL_OVERRIDE',
            $this->userId
        );

        // Second match on same exact elements fails due to Immutability (payment is now RECONCILED)
        $this->expectException(ReconciliationCommitException::class);

        $this->service->commit1to1(
            $this->session,
            $line->id,
            VendorPayment::class,
            $payment->id,
            1000.0,
            'MANUAL_OVERRIDE',
            $this->userId
        );
    }
}
