<?php

namespace Tests\Feature\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\ReconciliationSession;
use Modules\Finance\Banking\Models\ReconciliationMatch;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Payables\Models\PaymentVoucher;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;
use Illuminate\Support\Str;

class ReconciliationSessionModuleTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Operations\Concerns\CreatesPurchasingData;

    private User $user;
    private $property;
    private $otherProperty;
    private BankAccount $bankAccount;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->otherProperty = $this->createProperty($company);
        
        $this->user = $this->createPropertyAdmin($this->property);

        Permission::firstOrCreate(['name' => 'banking.reconciliation.view']);
        Permission::firstOrCreate(['name' => 'banking.reconciliation.create']);
        Permission::firstOrCreate(['name' => 'banking.reconciliation.manage']);
        
        $this->user->givePermissionTo([
            'banking.reconciliation.view',
            'banking.reconciliation.create',
            'banking.reconciliation.manage',
        ]);

        $this->bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'opening_balance' => 1000,
            'current_balance' => 1000,
            'reconciled_balance' => 500,
        ]);
    }

    public function test_cannot_create_multiple_active_sessions_for_same_bank_account()
    {
        ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Open,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/reconciliations', [
                'bank_account_id' => $this->bankAccount->id,
                'statement_date_start' => '2026-06-01',
                'statement_date_end' => '2026-06-30',
                'opening_balance' => 500,
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(422);
        $this->assertEquals('An active reconciliation session already exists for this bank account.', $response->json('message'));
    }

    public function test_completing_session_updates_bank_account_reconciled_balance()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Review,
            'reconciled_balance' => 800,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$session->id}/complete", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        
        $this->bankAccount->refresh();
        $this->assertEquals(800, $this->bankAccount->reconciled_balance);
        $this->assertEquals(ReconciliationSessionStatusEnum::Completed, $session->fresh()->status);
    }

    public function test_completing_session_locks_matches()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Review,
        ]);

        $statement = BankStatement::factory()->create(['property_id' => $this->property->id, 'bank_account_id' => $this->bankAccount->id]);
        $line = BankStatementLine::factory()->create(['property_id' => $this->property->id, 'bank_statement_id' => $statement->id]);

        $match = ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $line->id,
            'is_locked' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$session->id}/complete", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertTrue($match->fresh()->is_locked);
    }

    public function test_completed_session_is_immutable()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Completed,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$session->id}/cancel", [], ['X-Property-Id' => $this->property->id]);



        $response->assertStatus(422);

        $responseDelete = $this->actingAs($this->user)
            ->deleteJson("/api/v1/banking/reconciliations/{$session->id}", [], ['X-Property-Id' => $this->property->id]);

        $responseDelete->assertStatus(422);
    }

    public function test_cancelled_session_leaves_audit_trail()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'status' => ReconciliationSessionStatusEnum::Open,
        ]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/reconciliations/{$session->id}/cancel", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);

        $logs = AuditLog::where('auditable_type', ReconciliationSession::class)
            ->where('auditable_id', $session->id)
            ->get();

        $this->assertNotEmpty($logs);
        $this->assertEquals(ReconciliationSessionStatusEnum::Cancelled, $session->fresh()->status);
        $this->assertEquals($this->user->id, $session->fresh()->cancelled_by);
    }

    public function test_session_is_isolated_by_property()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->otherProperty->id,
            'bank_account_id' => BankAccount::factory()->create(['property_id' => $this->otherProperty->id])->id,
            'status' => ReconciliationSessionStatusEnum::Open,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/reconciliations/{$session->id}", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(404);
    }

    public function test_bank_statement_line_cannot_be_matched_twice()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
        ]);

        $statement = BankStatement::factory()->create(['property_id' => $this->property->id, 'bank_account_id' => $this->bankAccount->id]);
        $line = BankStatementLine::factory()->create(['property_id' => $this->property->id, 'bank_statement_id' => $statement->id]);

        ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $line->id,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        
        ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $line->id,
        ]);
    }

    public function test_payment_voucher_cannot_be_reconciled_twice()
    {
        $session = ReconciliationSession::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
        ]);

        $statement = BankStatement::factory()->create(['property_id' => $this->property->id, 'bank_account_id' => $this->bankAccount->id]);
        $line1 = BankStatementLine::factory()->create(['property_id' => $this->property->id, 'bank_statement_id' => $statement->id]);
        $line2 = BankStatementLine::factory()->create(['property_id' => $this->property->id, 'bank_statement_id' => $statement->id]);

        $voucherId = (string) Str::ulid();

        ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $line1->id,
            'matchable_type' => PaymentVoucher::class,
            'matchable_id' => $voucherId,
        ]);

        $this->expectException(\Illuminate\Database\QueryException::class);
        
        ReconciliationMatch::factory()->create([
            'property_id' => $this->property->id,
            'reconciliation_session_id' => $session->id,
            'bank_statement_line_id' => $line2->id,
            'matchable_type' => PaymentVoucher::class,
            'matchable_id' => $voucherId,
        ]);
    }
}
