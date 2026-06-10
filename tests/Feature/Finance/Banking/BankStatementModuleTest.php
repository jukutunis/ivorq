<?php

namespace Tests\Feature\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Enums\BankStatementStatusEnum;
use Modules\Foundation\Audit\Models\AuditLog;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class BankStatementModuleTest extends TestCase
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

        Permission::firstOrCreate(['name' => 'banking.statement.view']);
        Permission::firstOrCreate(['name' => 'banking.statement.create']);
        Permission::firstOrCreate(['name' => 'banking.statement.import']);
        
        $this->user->givePermissionTo([
            'banking.statement.view',
            'banking.statement.create',
            'banking.statement.import',
        ]);

        $this->bankAccount = BankAccount::factory()->create([
            'property_id' => $this->property->id,
            'opening_balance' => 1000,
            'current_balance' => 1000,
        ]);
    }

    public function test_can_create_bank_statement()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/statements', [
                'bank_account_id' => $this->bankAccount->id,
                'statement_date' => '2026-06-01',
                'opening_balance' => 1000,
                'imported_closing_balance' => 900,
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201)
            ->assertJsonPath('data.opening_balance', '1000.00')
            ->assertJsonPath('data.status', 'Draft');
    }

    public function test_can_import_csv_statement()
    {
        $statement = BankStatement::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-06-01',
            'opening_balance' => 1000,
            'imported_closing_balance' => 900,
            'status' => BankStatementStatusEnum::Draft,
        ]);

        $csvContent = "transaction_date,description,reference,amount\n2026-06-01,Transfer Out,REF001,-100\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/statements/{$statement->id}/import", [
                'file' => $file
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status', 'Imported')
            ->assertJsonPath('data.closing_balance', '900.00');

        $this->assertCount(1, $statement->fresh()->lines);
    }

    public function test_cannot_import_duplicate_lines()
    {
        $statement = BankStatement::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-06-01',
            'opening_balance' => 1000,
            'imported_closing_balance' => 800,
            'status' => BankStatementStatusEnum::Draft,
        ]);

        $csvContent = "transaction_date,description,reference,amount\n2026-06-01,Transfer Out,REF001,-100\n2026-06-01,Transfer Out,REF001,-100\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/statements/{$statement->id}/import", [
                'file' => $file
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(422); // Throws unique constraint exception from DB caught by controller as 422
    }

    public function test_statement_belongs_to_property()
    {
        $statement = BankStatement::factory()->create([
            'property_id' => $this->otherProperty->id,
            'bank_account_id' => BankAccount::factory()->create(['property_id' => $this->otherProperty->id])->id,
        ]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/v1/banking/statements/{$statement->id}", ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(404);
    }

    public function test_variance_detection()
    {
        $statement = BankStatement::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-06-01',
            'opening_balance' => 1000,
            'imported_closing_balance' => 500, // We imported 500
            'status' => BankStatementStatusEnum::Draft,
        ]);

        // But lines only deduct 100, so calculated closing is 900
        $csvContent = "transaction_date,description,reference,amount\n2026-06-01,Transfer Out,REF001,-100\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/statements/{$statement->id}/import", [
                'file' => $file
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(200);

        $statement->refresh();
        $this->assertEquals(900, $statement->closing_balance);
        $this->assertEquals(500, $statement->imported_closing_balance);
        $this->assertNotEquals($statement->closing_balance, $statement->imported_closing_balance);
    }

    public function test_audit_log_created()
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/v1/banking/statements', [
                'bank_account_id' => $this->bankAccount->id,
                'statement_date' => '2026-06-02',
                'opening_balance' => 1000,
                'imported_closing_balance' => 900,
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(201);
        $statementId = $response->json('data.id');

        $logs = AuditLog::where('auditable_type', BankStatement::class)
            ->where('auditable_id', $statementId)
            ->get();

        $this->assertNotEmpty($logs);
    }

    public function test_imported_statement_is_immutable()
    {
        $statement = BankStatement::factory()->create([
            'property_id' => $this->property->id,
            'bank_account_id' => $this->bankAccount->id,
            'statement_date' => '2026-06-01',
            'opening_balance' => 1000,
            'imported_closing_balance' => 900,
            'status' => BankStatementStatusEnum::Imported,
        ]);

        $csvContent = "transaction_date,description,reference,amount\n2026-06-01,Transfer Out,REF001,-100\n";
        $file = UploadedFile::fake()->createWithContent('statement.csv', $csvContent);

        $response = $this->actingAs($this->user)
            ->postJson("/api/v1/banking/statements/{$statement->id}/import", [
                'file' => $file
            ], ['X-Property-Id' => $this->property->id]);

        $response->assertStatus(422); // Re-import not allowed
        $this->assertEquals('Only Draft statements can be imported or re-imported. This statement is Imported', $response->json('message'));
    }
}
