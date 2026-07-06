<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankReconciliationSessionWorkspaceTest extends PostgresTestCase
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

    public function test_unauthenticated_cannot_access_workspace(): void
    {
        $this->createFixtures();

        $this->get(route('finance.banking.operations.index'))
            ->assertRedirect();
    }

    public function test_authenticated_actor_can_load_workspace_with_session_evidence(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $sessions = $props['reconciliation_sessions'] ?? [];
        $this->assertGreaterThan(0, count($sessions));

        $permissions = $props['permissions'] ?? [];
        $this->assertArrayHasKey('can_view_reconciliation_sessions', $permissions);
    }

    public function test_property_isolation_for_reconciliation_sessions(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $sessions = $props['reconciliation_sessions'] ?? [];
        $this->assertGreaterThan(0, count($sessions));

        $crossResponse = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $crossProps = $crossResponse->inertiaProps();
        $crossSessions = $crossProps['reconciliation_sessions'] ?? [];
        $this->assertCount(0, $crossSessions);
    }

    public function test_no_mutation_when_viewing_sessions(): void
    {
        $this->createFixtures();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_no_role_permission_mutation(): void
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

    public function test_browser_query_injection_cannot_reveal_unscoped_sessions(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index') . '?property_id=' . $this->otherProperty->id)
            ->assertOk();

        $props = $response->inertiaProps();
        $sessions = $props['reconciliation_sessions'] ?? [];
        $this->assertGreaterThan(0, count($sessions));
    }

    public function test_capability_flags_are_server_projected(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $permissions = $props['permissions'] ?? [];

        $this->assertIsBool($permissions['can_view_reconciliation_sessions'] ?? null);
    }

    public function test_controlled_empty_state(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $company = Company::create([
            'name' => 'Empty Session Company ' . $companySuffix,
            'slug' => 'empty-session-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'Empty Session Property ' . $companySuffix,
            'slug' => 'empty-session-property-' . $companySuffix,
            'code' => 'ESP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);

        $user = User::create([
            'name' => 'Empty Session User ' . $companySuffix,
            'email' => 'empty-session-user-' . $companySuffix . '@example.test',
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
        $this->assertCount(0, $props['reconciliation_sessions'] ?? []);
    }

    public function test_banking_ownership_preserved(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/BankingOperationsWorkspace'));

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/CashbookEvidenceWorkspace'));
    }

    public function test_no_cashbook_or_cash_session_mutation(): void
    {
        $this->createFixtures();

        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeSessions = DB::table('cashier_sessions')->count();
        $beforeInstruments = DB::table('cashier_payment_instruments')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeSessions, DB::table('cashier_sessions')->count());
        $this->assertSame($beforeInstruments, DB::table('cashier_payment_instruments')->count());
    }

    public function test_no_payment_execution_or_reconciliation_mutation(): void
    {
        $this->createFixtures();

        $beforeExec = DB::table('payment_executions')->count();
        $beforeRecon = DB::table('bank_payment_reconciliations')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($beforeExec, DB::table('payment_executions')->count());
        $this->assertSame($beforeRecon, DB::table('bank_payment_reconciliations')->count());
    }

    public function test_no_reconciliation_session_mutation(): void
    {
        $this->createFixtures();

        $before = DB::table('reconciliation_sessions')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($before, DB::table('reconciliation_sessions')->count());
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'ReconSession WS Company ' . $companySuffix,
            'slug' => 'recon-session-ws-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'ReconSession WS Property ' . $companySuffix,
            'slug' => 'recon-session-ws-property-' . $companySuffix,
            'code' => 'RSS' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'ReconSession WS Other ' . $companySuffix,
            'slug' => 'recon-session-ws-other-' . $companySuffix,
            'code' => 'RSO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = User::create([
            'name' => 'ReconSession WS Actor ' . $companySuffix,
            'email' => 'recon-session-ws-actor-' . $companySuffix . '@example.test',
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
        $bankAccountId = (string) Str::ulid();
        $sessionId = (string) Str::ulid();

        DB::table('bank_accounts')->insert([
            'id' => $bankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'ReconSession Test Bank',
            'account_name' => 'ReconSession Operating Account',
            'account_number' => 'RSS-ACC-' . $companySuffix,
            'currency_code' => 'IDR',
            'opening_balance' => '1000.00',
            'current_balance' => '1000.00',
            'reconciled_balance' => '500.00',
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('reconciliation_sessions')->insert([
            'id' => $sessionId,
            'property_id' => $this->property->id,
            'bank_account_id' => $bankAccountId,
            'statement_date_start' => '2026-06-01',
            'statement_date_end' => '2026-06-30',
            'opening_balance' => '1000.00',
            'reconciled_balance' => '500.00',
            'unreconciled_balance' => '500.00',
            'status' => ReconciliationSessionStatusEnum::InProgress->value,
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
            'reconciliation_sessions',
            'bank_accounts',
            'controlled_bank_accounts',
            'controlled_bank_statement_lines',
            'payment_executions',
            'bank_payment_reconciliations',
            'gl_journal_entries',
            'cashbook_transactions',
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
