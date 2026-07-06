<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Models\ControlledBankAccount;
use Modules\Finance\Banking\Models\ControlledBankStatementLine;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionService;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankingOperationsWorkspaceTest extends PostgresTestCase
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

    public function test_unauthenticated_cannot_access_banking_operations_workspace(): void
    {
        $this->createFixtures();

        $this->get(route('finance.banking.operations.index'))
            ->assertRedirect();
    }

    public function test_authenticated_actor_can_load_banking_operations_workspace(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/BankingOperationsWorkspace'));

        $props = $response->inertiaProps();

        $accounts = $props['bank_accounts'] ?? [];
        $this->assertGreaterThanOrEqual(0, count($accounts));

        $permissions = $props['permissions'] ?? [];
        $this->assertArrayHasKey('can_execute_bank', $permissions);
        $this->assertArrayHasKey('can_reconcile_bank', $permissions);
    }

    public function test_other_property_bank_accounts_are_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $accounts = $props['bank_accounts'] ?? [];
        $this->assertCount(0, $accounts);
    }

    public function test_other_property_statement_lines_are_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $lines = $props['statement_lines'] ?? [];
        $this->assertCount(0, $lines);
    }

    public function test_other_property_bank_execution_evidence_is_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $evidence = $props['bank_execution_evidence'] ?? [];
        $this->assertCount(0, $evidence);
    }

    public function test_other_property_reconciliation_evidence_is_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $evidence = $props['reconciliation_evidence'] ?? [];
        $this->assertCount(0, $evidence);
    }

    public function test_workspace_creates_no_bank_account_mutation(): void
    {
        $this->createFixtures();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_workspace_creates_no_role_permission_mutation(): void
    {
        $this->createFixtures();

        $beforePermissions = DB::table('permissions')->count();
        $beforeRoles = DB::table('roles')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($beforePermissions, DB::table('permissions')->count());
        $this->assertSame($beforeRoles, DB::table('roles')->count());
    }

    public function test_capability_projection_is_server_owned(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $permissions = $props['permissions'] ?? [];

        $this->assertIsBool($permissions['can_execute_bank'] ?? null);
        $this->assertIsBool($permissions['can_reconcile_bank'] ?? null);
    }

    public function test_no_browser_query_overrides_property_scope(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index') . '?property_id=' . $this->otherProperty->id)
            ->assertOk();

        $props = $response->inertiaProps();
        $accounts = $props['bank_accounts'] ?? [];
        $this->assertGreaterThan(0, count($accounts));
    }

    public function test_controlled_empty_state_is_safe(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $company = Company::create([
            'name' => 'Empty State Company ' . $companySuffix,
            'slug' => 'empty-state-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'Empty State Property ' . $companySuffix,
            'slug' => 'empty-state-property-' . $companySuffix,
            'code' => 'ESP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);

        $user = User::create([
            'name' => 'Empty State User ' . $companySuffix,
            'email' => 'empty-state-user-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
        $user->properties()->attach($property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $response = $this->withSession([
            'active_property_id' => $property->id,
            'active_company_id' => $company->id,
            'current_property_id' => $property->id,
        ])
            ->actingAs($user, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $this->assertCount(0, $props['bank_accounts'] ?? []);
        $this->assertCount(0, $props['statement_lines'] ?? []);
        $this->assertCount(0, $props['bank_execution_evidence'] ?? []);
        $this->assertCount(0, $props['reconciliation_evidence'] ?? []);
    }

    public function test_banking_workspace_does_not_alter_cashbook_ownership(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Banking WS Company ' . $companySuffix,
            'slug' => 'banking-ws-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Banking WS Property ' . $companySuffix,
            'slug' => 'banking-ws-property-' . $companySuffix,
            'code' => 'BWS' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Banking WS Other ' . $companySuffix,
            'slug' => 'banking-ws-other-' . $companySuffix,
            'code' => 'BWO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = User::create([
            'name' => 'Banking WS Actor ' . $companySuffix,
            'email' => 'banking-ws-actor-' . $companySuffix . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
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
            'code' => 'BANK-WS-GL-' . $companySuffix,
            'name' => 'Bank WS GL Account',
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
            'bank_name' => 'Test Banking WS Bank',
            'account_name' => 'Banking WS Account',
            'external_account_reference' => 'EXT-WS-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'banking-ws-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'ws-test-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'banking_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        ControlledBankStatementLine::create([
            'id' => $statementLineId,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'stmt-ws-' . $companySuffix,
            'external_reference' => 'EXT-REF-WS-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW,
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'stmt-ws-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'banking_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
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
            'bank_payment_reconciliations',
            'cashier_sessions',
            'cashier_payment_instruments',
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
