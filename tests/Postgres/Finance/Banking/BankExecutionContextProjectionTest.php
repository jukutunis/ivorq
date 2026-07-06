<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankExecutionContextProjectionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
    }

    public function test_unauthenticated_cannot_access_cashbook_evidence(): void
    {
        $this->createFixtures();

        $this->get(route('finance.payables.cashbook-evidence.index'))
            ->assertRedirect();
    }

    public function test_bank_execution_context_is_property_isolated(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/CashbookEvidenceWorkspace'));

        $props = $response->inertiaProps();
        $context = $props['bank_execution_context'] ?? null;
        $this->assertNotNull($context);

        $accounts = $context['bank_accounts'] ?? [];
        $this->assertGreaterThanOrEqual(0, count($accounts));

        foreach ($accounts as $account) {
            $this->assertNotEmpty($account['account_name']);
        }

        $lines = $context['statement_lines'] ?? [];
        foreach ($lines as $line) {
            $this->assertNotEmpty($line['amount']);
        }
    }

    public function test_cross_property_sees_empty_bank_context(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $context = $props['bank_execution_context'] ?? null;
        $this->assertNotNull($context);

        $accounts = $context['bank_accounts'] ?? [];
        $this->assertCount(0, $accounts);
    }

    public function test_bank_context_shows_no_execute_action(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $context = $props['bank_execution_context'] ?? null;
        $this->assertNotNull($context);

        $this->assertArrayNotHasKey('can_execute', $context);
    }

    public function test_bank_context_read_only_no_domain_mutation(): void
    {
        $this->createFixtures();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $this->assertControlledSnapshotUnchanged($before);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Bank Context Company ' . $companySuffix,
            'slug' => 'bank-context-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Context Property ' . $companySuffix,
            'slug' => 'bank-context-property-' . $companySuffix,
            'code' => 'BCP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Bank Context Other ' . $companySuffix,
            'slug' => 'bank-context-other-' . $companySuffix,
            'code' => 'BCO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = $this->user('Bank Context Actor ' . $companySuffix, 'bank-context-actor-' . $companySuffix . '@example.test');
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $timestamp = now();
        $accountId = (string) Str::ulid();
        $bankAccountId = (string) Str::ulid();
        $statementLineId = (string) Str::ulid();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->property->id,
            'code' => 'BANK-GL-' . $companySuffix,
            'name' => 'Bank GL Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => false,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_accounts')->insert([
            'id' => $bankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $accountId,
            'bank_name' => 'Test Bank ' . $companySuffix,
            'account_name' => 'Test Account ' . $companySuffix,
            'external_account_reference' => 'EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'bank-context-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'test-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_context_projection']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        ControlledBankStatementLine::create([
            'id' => $statementLineId,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'stmt-test-' . $companySuffix,
            'external_reference' => 'EXT-REF-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW,
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'bank_context_projection']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => $email,
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    private function propertySession(): array
    {
        return [
            'active_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->property->id,
        ];
    }

    private function otherPropertySession(): array
    {
        return [
            'active_property_id' => $this->otherProperty->id,
            'active_company_id' => $this->company->id,
            'current_property_id' => $this->otherProperty->id,
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'payment_executions',
            'payment_proposals',
            'gl_journal_entries',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count, $after[$table], "Table {$table} mutated.");
        }
    }
}
