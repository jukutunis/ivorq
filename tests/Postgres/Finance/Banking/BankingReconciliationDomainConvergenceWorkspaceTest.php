<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingReconciliationDomainConvergenceWorkspaceTest extends PostgresTestCase
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

    public function test_domain_sections_prop_is_present(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $sections = $props['domain_sections'] ?? null;
        $this->assertNotNull($sections);
        $this->assertArrayHasKey('controlled', $sections);
        $this->assertArrayHasKey('legacy', $sections);
        $this->assertNotEmpty($sections['controlled']['label']);
        $this->assertNotEmpty($sections['legacy']['label']);
    }

    public function test_controlled_evidence_is_property_scoped(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $accounts = $props['bank_accounts'] ?? [];
        $this->assertGreaterThan(0, count($accounts));
        $this->assertSame($this->property->id, DB::table('controlled_bank_accounts')->first()->property_id);
    }

    public function test_legacy_session_evidence_is_property_scoped(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $sessions = $props['reconciliation_sessions'] ?? [];
        $this->assertGreaterThan(0, count($sessions));
    }

    public function test_cross_property_controlled_evidence_does_not_leak_into_legacy(): void
    {
        $this->createFixtures();

        $crossResponse = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $crossProps = $crossResponse->inertiaProps();
        $crossAccounts = $crossProps['bank_accounts'] ?? [];
        $crossSessions = $crossProps['reconciliation_sessions'] ?? [];
        $this->assertCount(0, $crossAccounts);
        $this->assertCount(0, $crossSessions);
    }

    public function test_browser_query_cannot_coerce_cross_domain_mapping(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index') . '?property_id=' . $this->otherProperty->id . '&domain=legacy')
            ->assertOk();

        $props = $response->inertiaProps();
        $accounts = $props['bank_accounts'] ?? [];
        $this->assertGreaterThan(0, count($accounts));
    }

    public function test_workspace_is_read_only_no_domain_mutation(): void
    {
        $this->createFixtures();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertControlledSnapshotUnchanged($before);
    }

    public function test_no_balance_or_variance_field_projected(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('opening_balance', $content);
        $this->assertStringNotContainsString('reconciled_balance', $content);
        $this->assertStringNotContainsString('unreconciled_balance', $content);
        $this->assertStringNotContainsString('current_balance', $content);
    }

    public function test_no_role_or_permission_mutation(): void
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

    public function test_no_legacy_record_represented_as_controlled_evidence(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $accounts = $props['bank_accounts'] ?? [];

        $legacyBankAccountIds = DB::table('bank_accounts')->pluck('id')->all();
        foreach ($accounts as $account) {
            $this->assertNotContains($account['id'], $legacyBankAccountIds);
        }
    }

    public function test_no_controlled_record_represented_as_legacy(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $sessions = $props['reconciliation_sessions'] ?? [];

        $controlledAccountIds = DB::table('controlled_bank_accounts')->pluck('id')->all();
        foreach ($sessions as $session) {
            $this->assertNotContains($session['bank_account_id'], $controlledAccountIds);
        }
    }

    public function test_controlled_empty_state_and_legacy_empty_state_are_separate(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $company = Company::create([
            'name' => 'Empty Conv Company ' . $companySuffix,
            'slug' => 'empty-conv-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'Empty Conv Property ' . $companySuffix,
            'slug' => 'empty-conv-property-' . $companySuffix,
            'code' => 'ECV' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);

        $user = User::create([
            'name' => 'Empty Conv User ' . $companySuffix,
            'email' => 'empty-conv-user-' . $companySuffix . '@example.test',
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
        $this->assertCount(0, $props['reconciliation_sessions'] ?? []);
    }

    public function test_no_cashbook_or_payment_execution_mutation(): void
    {
        $this->createFixtures();

        $beforeCashbook = DB::table('cashbook_transactions')->count();
        $beforeExec = DB::table('payment_executions')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($beforeCashbook, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeExec, DB::table('payment_executions')->count());
    }

    public function test_controlled_readiness_is_server_projected(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['controlled_readiness'] ?? [];
        $this->assertGreaterThan(0, count($readiness));

        $entry = $readiness[0];
        $this->assertArrayHasKey('account_id', $entry);
        $this->assertArrayHasKey('account_name', $entry);
        $this->assertArrayHasKey('statement_line_count', $entry);
        $this->assertArrayHasKey('execution_count', $entry);
        $this->assertArrayHasKey('reconciled_count', $entry);
        $this->assertIsInt($entry['statement_line_count']);
        $this->assertIsInt($entry['execution_count']);
        $this->assertIsInt($entry['reconciled_count']);
    }

    public function test_controlled_readiness_only_reads_controlled_models(): void
    {
        $this->createFixtures();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame(1, DB::table('bank_accounts')->count());
        $this->assertSame(1, DB::table('controlled_bank_accounts')->count());
    }

    public function test_controlled_readiness_does_not_mutate(): void
    {
        $this->createFixtures();

        $before = $this->controlledSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertControlledSnapshotUnchanged($before);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'DomainConv Company ' . $companySuffix,
            'slug' => 'domain-conv-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'DomainConv Property ' . $companySuffix,
            'slug' => 'domain-conv-property-' . $companySuffix,
            'code' => 'DCV' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'DomainConv Other ' . $companySuffix,
            'slug' => 'domain-conv-other-' . $companySuffix,
            'code' => 'DCO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = User::create([
            'name' => 'DomainConv Actor ' . $companySuffix,
            'email' => 'domain-conv-actor-' . $companySuffix . '@example.test',
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

        $legacyBankAccountId = (string) Str::ulid();
        DB::table('bank_accounts')->insert([
            'id' => $legacyBankAccountId,
            'property_id' => $this->property->id,
            'bank_name' => 'DomainConv Legacy Bank',
            'account_name' => 'Legacy Account',
            'account_number' => 'LEGACY-' . $companySuffix,
            'currency_code' => 'IDR',
            'opening_balance' => '500.00',
            'current_balance' => '500.00',
            'reconciled_balance' => '0.00',
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('reconciliation_sessions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'bank_account_id' => $legacyBankAccountId,
            'statement_date_start' => '2026-06-01',
            'statement_date_end' => '2026-06-30',
            'opening_balance' => '500.00',
            'reconciled_balance' => '0.00',
            'unreconciled_balance' => '500.00',
            'status' => ReconciliationSessionStatusEnum::InProgress->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $glAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'DCV-GL-' . $companySuffix,
            'name' => 'DomainConv GL Account',
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

        $controlledBankAccountId = (string) Str::ulid();
        DB::table('controlled_bank_accounts')->insert([
            'id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'DomainConv Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'DCV-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'domain-conv-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'dcv-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'domain_conv']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'dcv-stmt-' . $companySuffix,
            'external_reference' => 'DCV-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'dcv-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'domain_conv']),
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
            'bank_accounts',
            'bank_statement_lines',
            'reconciliation_matches',
            'reconciliation_sessions',
            'bank_payment_reconciliations',
            'payment_executions',
            'cashbook_transactions',
            'cashier_sessions',
            'cashier_payment_instruments',
            'gl_journal_entries',
            'payment_proposals',
            'payment_proposal_items',
            'journal_candidates',
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
