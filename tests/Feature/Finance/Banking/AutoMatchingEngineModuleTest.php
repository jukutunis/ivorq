<?php

namespace Tests\Feature\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Authorization\Models\Permission;
use Tests\TestCase;

class AutoMatchingEngineModuleTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Operations\Concerns\CreatesPurchasingData;

    private User $user;
    private $property;
    private BankAccount $bankAccount;
    private BankStatement $statement;
    private ReconciliationSession $session;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        
        $this->user = $this->createPropertyAdmin($this->property);

        Permission::firstOrCreate(['name' => 'banking.reconciliation.view']);
        Permission::firstOrCreate(['name' => 'banking.reconciliation.manage']);
        
        $this->user->givePermissionTo([
            'banking.reconciliation.view',
            'banking.reconciliation.manage',
        ]);

        $this->bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $this->statement = BankStatement::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
        ]);

        $this->session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date_start' => '2026-06-01',
            'statement_date_end' => '2026-06-30',
            'status' => ReconciliationSessionStatusEnum::Open,
        ]);
    }

    public function test_auto_match_never_writes_to_database()
    {
        BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -100,
            'reference' => 'INV-001',
        ]);

        PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 100,
            'reference_no' => 'INV-001',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        
        // Ensure NO matches were saved to DB
        $this->assertDatabaseCount('reconciliation_matches', 0);
    }

    public function test_auto_match_uses_absolute_statement_amount_for_payment_vouchers()
    {
        BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -500, // Negative in statement (withdrawal)
            'reference' => 'VOUCHER-500',
        ]);

        PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 500, // Positive in payable
            'reference_no' => 'VOUCHER-500',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('ExactMatch', $response->json('data.0.rule_applied'));
    }

    public function test_engine_matches_exact_amount_and_reference()
    {
        $line = BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -250,
            'reference' => 'REF-EXACT',
        ]);

        $voucher = PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-25', // 10 days apart, but exact ref match should supersede date tolerance
            'total_amount' => 250,
            'reference_no' => 'REF-EXACT',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals($line->id, $response->json('data.0.bank_statement_line_id'));
        $this->assertEquals($voucher->id, $response->json('data.0.matchable_id'));
        $this->assertEquals('ExactMatch', $response->json('data.0.rule_applied'));
    }

    public function test_engine_matches_exact_amount_and_date_tolerance()
    {
        BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -300,
            'reference' => 'SOMETHING',
        ]);

        PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-16', // Within 2 days
            'total_amount' => 300,
            'reference_no' => 'DIFFERENT',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertEquals('DateToleranceMatch', $response->json('data.0.rule_applied'));
    }

    public function test_engine_skips_ambiguous_matches()
    {
        BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -100,
            'reference' => '',
        ]);

        // Two identical vouchers
        PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 100,
            'reference_no' => '',
            'status' => 'Posted',
        ]);
        PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 100,
            'reference_no' => '',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        // Should return 0 matches because it's ambiguous
        $this->assertCount(0, $response->json('data'));
    }

    public function test_engine_ignores_already_matched_lines_and_vouchers()
    {
        $line = BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -100,
            'reference' => 'REF1',
            'is_reconciled' => true,
        ]);

        $voucher = PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 100,
            'reference_no' => 'REF1',
            'status' => 'Posted',
        ]);

        // Create an existing match
        \Modules\Finance\Banking\Models\ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $this->session->id,
            'bank_statement_line_id' => $line->id,
            'matchable_type' => PaymentVoucher::class,
            'matchable_id' => $voucher->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$this->session->id}/auto-match", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_saving_matches_enforces_session_status()
    {
        $this->session->update(['status' => ReconciliationSessionStatusEnum::Completed]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$this->session->id}/matches", [
                'matches' => [
                    [
                        'bank_statement_line_id' => (string) \Illuminate\Support\Str::ulid(),
                        'matchable_type' => PaymentVoucher::class,
                        'matchable_id' => (string) \Illuminate\Support\Str::ulid(),
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Cannot save matches to a session that is already Completed', $response->json('message'));
    }

    public function test_saving_matches_creates_proper_snapshots()
    {
        $line = BankStatementLine::factory()->create([
            'property_id' => $this->property->id,
            'bank_statement_id' => $this->statement->id,
            'transaction_date' => '2026-06-15',
            'amount' => -200,
            'reference' => 'REF-SNAP',
        ]);

        $voucher = PaymentVoucher::factory()->create([
            'property_id' => $this->property->id,
            'payment_date' => '2026-06-15',
            'total_amount' => 200,
            'reference_no' => 'REF-SNAP',
            'status' => 'Posted',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$this->session->id}/matches", [
                'matches' => [
                    [
                        'bank_statement_line_id' => $line->id,
                        'matchable_type' => PaymentVoucher::class,
                        'matchable_id' => $voucher->id,
                    ]
                ]
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('reconciliation_matches', [
            'bank_statement_line_id' => $line->id,
            'matchable_id' => $voucher->id,
            'statement_reference' => 'REF-SNAP',
            'statement_amount' => -200,
            'matchable_reference' => 'REF-SNAP',
            'matchable_amount' => 200,
            'amount_matched' => 200,
        ]);
    }
}
