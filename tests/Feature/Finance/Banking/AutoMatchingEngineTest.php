<?php

namespace Tests\Feature\Finance\Banking;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Carbon\Carbon;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Treasury\Models\VendorPayment;
use Modules\Finance\Treasury\Enums\VendorPaymentStatusEnum;
use Modules\Finance\Banking\Services\Matching\AutoMatchingEngine;
use Modules\Finance\Banking\DTOs\MatchingConfiguration;

class AutoMatchingEngineTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected AutoMatchingEngine $engine;
    protected $property;
    protected $bankAccount;
    protected $statement;

    protected function setUp(): void
    {
        parent::setUp();
        
        $config = new MatchingConfiguration(
            date_tolerance_days: 3,
            amount_tolerance: 1000.0,
            reference_similarity_percent: 80.0,
            auto_match_threshold: 95.0
        );

        $this->engine = new AutoMatchingEngine($config);

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
    }

    protected function createBankLine(float $amount, string $date, ?string $ref = null): BankStatementLine
    {
        return BankStatementLine::create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => $date,
            'description' => 'Test',
            'reference' => $ref,
            'amount' => $amount,
            'is_reconciled' => false,
        ]);
    }

    protected function createPayment(float $amount, string $date, string $status, ?string $ref = null): VendorPayment
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
            'payment_date' => $date,
            'status' => $status,
            'payment_number' => $ref ?? 'PAY-' . rand(1000, 9999),
        ]);
    }

    public function test_exact_match()
    {
        $line = $this->createBankLine(-10000.0, '2023-01-05', 'REF123');
        $payment = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123');

        $candidates = $this->engine->findCandidates($line);
        $this->assertCount(1, $candidates);

        $result = $this->engine->evaluate($line, $candidates);

        $this->assertTrue($result->is_match);
        $this->assertEquals('Exact Match', $result->reason);
        $this->assertEquals(100.0, $result->confidence_score);
        $this->assertEquals($payment->id, $result->candidate->matchable_id);
    }

    public function test_date_variance()
    {
        $line = $this->createBankLine(-10000.0, '2023-01-06', 'REF123'); // cleared on 6th
        $payment = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123'); // initiated on 5th (3 days diff)

        $candidates = $this->engine->findCandidates($line);
        $this->assertCount(1, $candidates);

        $result = $this->engine->evaluate($line, $candidates);

        $this->assertTrue($result->is_match);
        $this->assertEquals('Auto Match Candidate', $result->reason); // 95%
        $this->assertEquals(95.0, $result->confidence_score);
    }

    public function test_reference_variance()
    {
        // One character different in 7 string = 85% similar (above 80% tolerance)
        $line = $this->createBankLine(-10000.0, '2023-01-05', 'PAY-123');
        $payment = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'PAY-124');

        $candidates = $this->engine->findCandidates($line);
        $this->assertCount(1, $candidates);

        $result = $this->engine->evaluate($line, $candidates);

        $this->assertFalse($result->is_match);
        $this->assertEquals('Suggested Match', $result->reason);
        $this->assertEquals(90.0, $result->confidence_score);
    }

    public function test_amount_variance()
    {
        $line = $this->createBankLine(-10500.0, '2023-01-05', 'REF123'); // 500 diff
        $payment = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123');

        $candidates = $this->engine->findCandidates($line);
        $this->assertCount(1, $candidates);

        $result = $this->engine->evaluate($line, $candidates);

        $this->assertFalse($result->is_match);
        $this->assertEquals('Suggested Match', $result->reason); // 80.0
        $this->assertEquals(80.0, $result->confidence_score);
    }

    public function test_multiple_candidates_ranking_order()
    {
        $line = $this->createBankLine(-10000.0, '2023-01-05', 'REF123');
        
        // Exact
        $p1 = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123');
        
        // Date Variance (Score 95) - We will just change the date, but must use a different payment_number to avoid unique constraint
        $p2 = $this->createPayment(10000.0, '2023-01-04', VendorPaymentStatusEnum::Executed->value, 'REF123_2');
        
        // Amount Variance (Score 80)
        $p3 = $this->createPayment(10500.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123_3');

        $candidates = $this->engine->findCandidates($line);
        
        // Should find 3 candidates
        $this->assertCount(3, $candidates);

        // Ranking should be Exact, Date Variance, Amount Variance
        $this->assertEquals($p1->id, $candidates[0]->matchable_id);
        $this->assertEquals($p2->id, $candidates[1]->matchable_id);
        $this->assertEquals($p3->id, $candidates[2]->matchable_id);

        $result = $this->engine->evaluate($line, $candidates);
        $this->assertTrue($result->is_match);
        $this->assertEquals('Exact Match', $result->reason);
    }

    public function test_below_threshold()
    {
        // 2000 diff (tolerance is 1000)
        $line = $this->createBankLine(-12000.0, '2023-01-05', 'REF123');
        $payment = $this->createPayment(10000.0, '2023-01-05', VendorPaymentStatusEnum::Executed->value, 'REF123');

        $candidates = $this->engine->findCandidates($line);
        // It won't even find it because of whereBetween bounds in discovery
        $this->assertCount(0, $candidates);

        $result = $this->engine->evaluate($line, $candidates);
        $this->assertFalse($result->is_match);
        $this->assertEquals('No candidates found.', $result->reason);
    }

    public function test_no_candidates()
    {
        $line = $this->createBankLine(-10000.0, '2023-01-05', 'REF123');
        $result = $this->engine->evaluate($line, []);
        $this->assertFalse($result->is_match);
        $this->assertEquals('No candidates found.', $result->reason);
    }
}
