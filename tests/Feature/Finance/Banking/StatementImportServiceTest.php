<?php

namespace Tests\Feature\Finance\Banking;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Feature\Foundation\Concerns\CreatesFoundationData;
use Modules\Finance\Banking\Models\BankAccount;
use Modules\Finance\Banking\Models\BankStatement;
use Modules\Finance\Banking\Models\BankStatementLine;
use Modules\Finance\Banking\Services\StatementImportService;
use Modules\Finance\Banking\DTOs\ParsedStatementDTO;
use Modules\Finance\Banking\DTOs\ParsedStatementLineDTO;
use Modules\Finance\Banking\Exceptions\StatementImportException;
use Modules\Foundation\User\Models\User;

class StatementImportServiceTest extends TestCase
{
    use RefreshDatabase, CreatesFoundationData;

    protected StatementImportService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(StatementImportService::class);
    }

    public function test_successful_import_and_audit_tracking()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
            'opening_balance' => 0,
            'current_balance' => 0,
            'reconciled_balance' => 0,
        ]);

        $dto = new ParsedStatementDTO(
            statement_date: '2023-01-01',
            opening_balance: 1000.0,
            closing_balance: 1500.0,
            currency_code: 'USD',
            bank_account_reference: '12345'
        );

        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Deposit 1', 'REF1', null, 200.0));
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Deposit 2', 'REF2', null, 300.0));

        $user = \Modules\Foundation\User\Models\User::first();
        if (!$user) {
            $user = \Modules\Foundation\User\Models\User::create(['name'=>'t','email'=>'t@t.com','password'=>'1','is_active'=>true]);
        }

        $statement = $this->service->import(
            $dto, 
            $bankAccount, 
            $property, 
            'test_file.csv', 
            'hash123', 
            $user->id
        );

        $this->assertNotNull($statement);
        $this->assertEquals('test_file.csv', $statement->file_name);
        $this->assertEquals('hash123', $statement->file_hash);
        $this->assertEquals(2, $statement->row_count);
        $this->assertEquals($user->id, $statement->imported_by);
        $this->assertNotNull($statement->imported_at);

        $lines = BankStatementLine::where('bank_statement_id', $statement->id)->get();
        $this->assertCount(2, $lines);
        $this->assertEquals(200.0, $lines[0]->amount);
        $this->assertEquals(300.0, $lines[1]->amount);
    }

    public function test_rollback_on_failure()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $dto = new ParsedStatementDTO(
            statement_date: '2023-01-01',
            opening_balance: 1000.0,
            closing_balance: 9999.0, // Invalid balance to force error
            currency_code: 'USD',
            bank_account_reference: '12345'
        );
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Dep', 'REF1', null, 100.0));

        try {
            $this->service->import($dto, $bankAccount, $property);
            $this->fail("Expected exception was not thrown.");
        } catch (StatementImportException $e) {
            $this->assertStringContainsString('Balance mismatch', $e->getMessage());
        }

        // Assert database is empty (rollback succeeded)
        $this->assertDatabaseCount('bank_statements', 0);
        $this->assertDatabaseCount('bank_statement_lines', 0);
    }

    public function test_duplicate_statement_rejected()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        // Pre-insert statement
        BankStatement::create([
            'property_id' => $property->id,
            'bank_account_id' => $bankAccount->id,
            'statement_date' => '2023-01-01',
            'opening_balance' => 0,
            'closing_balance' => 0,
        ]);

        $dto = new ParsedStatementDTO('2023-01-01', null, null, null, null);
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Dep', 'REF1', null, 100.0));

        $this->expectException(StatementImportException::class);
        $this->expectExceptionMessageMatches('/Duplicate statement import detected/');

        $this->service->import($dto, $bankAccount, $property);
    }

    public function test_duplicate_lines_within_file_rejected()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $dto = new ParsedStatementDTO('2023-01-01', null, null, null, null);
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Dep', 'REF1', 'EXT1', 100.0));
        // Duplicate line
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Dep', 'REF1', 'EXT1', 100.0));

        $this->expectException(StatementImportException::class);
        $this->expectExceptionMessageMatches('/Duplicate statement line detected/');

        $this->service->import($dto, $bankAccount, $property);
    }

    public function test_duplicate_lines_against_database_rejected()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $stmt1 = BankStatement::create([
            'property_id' => $property->id,
            'bank_account_id' => $bankAccount->id,
            'statement_date' => '2023-01-01',
            'opening_balance' => 0,
            'closing_balance' => 0,
        ]);

        BankStatementLine::create([
            'property_id' => $property->id,
            'bank_statement_id' => $stmt1->id,
            'transaction_date' => '2023-01-01',
            'description' => 'Existing',
            'amount' => 100.0,
            'external_reference' => 'EXT1'
        ]);

        $dto = new ParsedStatementDTO('2023-01-02', null, null, null, null);
        $dto->addLine(new ParsedStatementLineDTO('2023-01-02', 'Dep', 'REF1', 'EXT1', 100.0));

        $this->expectException(StatementImportException::class);
        $this->expectExceptionMessageMatches('/Duplicate statement line detected/');

        $this->service->import($dto, $bankAccount, $property);
    }

    public function test_property_isolation()
    {
        $company = $this->createCompany();
        $propertyA = $this->createProperty($company);
        $propertyB = $this->createProperty($company);
        
        $bankAccountA = BankAccount::create([
            'property_id' => $propertyA->id, // Belongs to A
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $dto = new ParsedStatementDTO('2023-01-01', null, null, null, null);
        
        $this->expectException(StatementImportException::class);
        $this->expectExceptionMessage('Bank account does not belong to the active property.');

        // Try importing against Property B
        $this->service->import($dto, $bankAccountA, $propertyB);
    }

    public function test_balance_mismatch_rejected()
    {
        $company = $this->createCompany();
        $property = $this->createProperty($company);
        
        $bankAccount = BankAccount::create([
            'property_id' => $property->id,
            'bank_name' => 'Test Bank',
            'account_name' => 'Main',
            'account_number' => '12345',
        ]);

        $dto = new ParsedStatementDTO('2023-01-01', 1000.0, 1500.0, null, null);
        // Opening = 1000. Adding 200 = 1200. Does not equal closing 1500.
        $dto->addLine(new ParsedStatementLineDTO('2023-01-01', 'Dep', 'REF1', null, 200.0));

        $this->expectException(StatementImportException::class);
        $this->expectExceptionMessageMatches('/Balance mismatch/');

        $this->service->import($dto, $bankAccount, $property);
    }
}
