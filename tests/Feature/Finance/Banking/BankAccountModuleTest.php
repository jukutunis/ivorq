<?php

namespace Tests\Feature\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Authorization\Models\Permission;
use Tests\TestCase;

class BankAccountModuleTest extends TestCase
{
    use RefreshDatabase, \Tests\Feature\Operations\Concerns\CreatesPurchasingData;

    private User $user;
    private $property;
    private $otherProperty;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed([\Database\Seeders\DatabaseSeeder::class]);
        
        $company = $this->createCompany();
        $this->property = $this->createProperty($company);
        $this->otherProperty = $this->createProperty($company);
        
        $this->user = $this->createPropertyAdmin($this->property);

        Permission::firstOrCreate(['name' => 'banking.bank-account.view']);
        Permission::firstOrCreate(['name' => 'banking.bank-account.create']);
        Permission::firstOrCreate(['name' => 'banking.bank-account.edit']);
        Permission::firstOrCreate(['name' => 'banking.bank-account.delete']);
        $this->user->givePermissionTo([
            'banking.bank-account.view',
            'banking.bank-account.create',
            'banking.bank-account.edit',
            'banking.bank-account.delete',
        ]);
    }

    public function test_can_create_bank_account()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/bank-accounts', [
                'bank_name' => 'Bank Mandiri',
                'account_name' => 'IVORQ Operations',
                'account_number' => '1234567890',
                'currency_code' => 'IDR',
                'opening_balance' => 1000000,
                ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.bank_name', 'Bank Mandiri')
            ->assertJsonPath('data.opening_balance', '1000000.00')
            ->assertJsonPath('data.current_balance', '1000000.00')
            ->assertJsonPath('data.reconciled_balance', '1000000.00');
    }

    public function test_account_number_must_be_unique_per_property()
    {
        BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'account_number' => '1234567890',
        ]);

        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/bank-accounts', [
                'bank_name' => 'Bank BCA',
                'account_name' => 'Operations',
                'account_number' => '1234567890',
                'opening_balance' => 0,
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(500); // Throws Integrity constraint violation
    }

    public function test_property_isolation_for_bank_account()
    {
        $bankAccount = BankAccount::factory()->create([
            'property_id' => $this->otherProperty->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/bank-accounts/{$bankAccount->id}", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(404);
    }

    public function test_audit_log_created()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/bank-accounts', [
                'bank_name' => 'Bank Mandiri',
                'account_name' => 'IVORQ Operations',
                'account_number' => '1234567890',
                'currency_code' => 'IDR',
                'opening_balance' => 1000000,
                ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201);
        $bankAccountId = $response->json('data.id');

        $logs = AuditLog::where('auditable_type', BankAccount::class)
            ->where('auditable_id', $bankAccountId)
            ->get();

        $this->assertNotEmpty($logs);
        $this->assertEquals('1234567890', collect($logs->last()->new_values)->get('account_number'));
    }

    public function test_soft_delete_bank_account()
    {
        $bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
        ]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/v1/banking/bank-accounts/{$bankAccount->id}", [], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(204);
        $this->assertSoftDeleted('bank_accounts', ['id' => $bankAccount->id]);
    }

    public function test_inactive_account_cannot_be_used()
    {
        $bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'is_active' => false,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/bank-accounts?is_active=true", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);
        $this->assertEmpty($response->json('data'));

        $response2 = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/bank-accounts?is_active=false", ['X-Property-Id' => $this->property->id]);

        $response2->assertStatus(200);
        $this->assertCount(1, $response2->json('data'));
    }
}
