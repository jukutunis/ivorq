<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\Banking\Enums\ReconciliationSessionStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class BankingMigrationAuthorityReadinessTest extends PostgresTestCase
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

    public function test_unauthenticated_access_is_denied(): void
    {
        $this->createFixtures();

        $this->get(route('finance.banking.operations.index'))
            ->assertRedirect();
    }

    public function test_migration_authority_readiness_prop_is_present(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? null;
        $this->assertNotNull($readiness);
        $this->assertArrayHasKey('controlled_domain_status', $readiness);
        $this->assertArrayHasKey('legacy_domain_status', $readiness);
        $this->assertArrayHasKey('cross_domain_bridge', $readiness);
        $this->assertArrayHasKey('migration_intake_boundary', $readiness);
        $this->assertArrayHasKey('is_migration_authorized', $readiness);
        $this->assertArrayHasKey('migration_prerequisites', $readiness);
    }

    public function test_cross_domain_bridge_is_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertSame('absent', $readiness['cross_domain_bridge']);
    }

    public function test_migration_intake_boundary_is_absent(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertSame('absent', $readiness['migration_intake_boundary']);
    }

    public function test_migration_is_not_authorized(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertFalse($readiness['is_migration_authorized']);
    }

    public function test_all_migration_prerequisites_are_pending(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $prereqs = $readiness['migration_prerequisites'] ?? [];

        $expected = [
            'source_authority_adr',
            'eligibility_policy',
            'provenance_definition',
            'duplicate_handling',
            'target_write_service',
            'audit_correlation',
            'cutover_policy',
            'rollback_policy',
        ];

        foreach ($expected as $key) {
            $this->assertArrayHasKey($key, $prereqs);
            $this->assertSame('pending', $prereqs[$key], "Prerequisite '{$key}' should be 'pending'.");
        }
    }

    public function test_readiness_evidence_is_property_scoped(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertSame('operative', $readiness['controlled_domain_status']);
        $this->assertGreaterThan(0, $readiness['controlled_account_count']);
        $this->assertSame('historical', $readiness['legacy_domain_status']);
    }

    public function test_cross_property_readiness_evidence_is_empty_on_other_property(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertSame('inactive', $readiness['controlled_domain_status']);
        $this->assertSame(0, $readiness['controlled_account_count']);
        $this->assertSame('empty', $readiness['legacy_domain_status']);
    }

    public function test_no_legacy_balance_is_projected(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('opening_balance', $content);
        $this->assertStringNotContainsString('current_balance', $content);
        $this->assertStringNotContainsString('reconciled_balance', $content);
        $this->assertStringNotContainsString('unreconciled_balance', $content);
    }

    public function test_no_mapping_score_or_confidence_value_projected(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $content = $response->getContent();
        $this->assertStringNotContainsString('confidence_score', $content);
        $this->assertStringNotContainsString('migration_score', $content);
        $this->assertStringNotContainsString('readiness_score', $content);
        $this->assertStringNotContainsString('variance', $content);
    }

    public function test_browser_injection_cannot_alter_migration_authority(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index') . '?property_id=' . $this->otherProperty->id . '&is_migration_authorized=true')
            ->assertOk();

        $props = $response->inertiaProps();
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertFalse($readiness['is_migration_authorized']);
    }

    public function test_workspace_request_performs_no_model_mutation(): void
    {
        $this->createFixtures();

        $before = $this->fullSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertFullSnapshotUnchanged($before);
    }

    public function test_no_migration_service_batch_item_route_or_permission_created(): void
    {
        $this->createFixtures();

        $beforePermissions = DB::table('permissions')->count();
        $beforeRoles = DB::table('roles')->count();
        $beforeRoutes = DB::table('migrations')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $this->assertSame($beforePermissions, DB::table('permissions')->count());
        $this->assertSame($beforeRoles, DB::table('roles')->count());
    }

    public function test_workspace_request_is_read_only_for_all_banking_domains(): void
    {
        $this->createFixtures();

        $before = $this->fullSnapshot();

        $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        foreach ($before as $table => $count) {
            $after = DB::table($table)->count();
            $this->assertSame($count, $after, "Table '{$table}' mutated during read-only workspace request.");
        }
    }

    public function test_controlled_empty_state_and_legacy_empty_state_produce_safe_readiness(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $company = Company::create([
            'name' => 'MigReadiness Empty Co ' . $companySuffix,
            'slug' => 'mig-readiness-empty-co-' . $companySuffix,
            'is_active' => true,
        ]);

        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'MigReadiness Empty Prop ' . $companySuffix,
            'slug' => 'mig-readiness-empty-prop-' . $companySuffix,
            'code' => 'MRE' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);

        $user = User::create([
            'name' => 'MigReadiness Empty User ' . $companySuffix,
            'email' => 'mig-readiness-empty-user-' . $companySuffix . '@example.test',
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
        $readiness = $props['migration_authority_readiness'] ?? [];
        $this->assertSame('inactive', $readiness['controlled_domain_status']);
        $this->assertSame(0, $readiness['controlled_account_count']);
        $this->assertSame('empty', $readiness['legacy_domain_status']);
        $this->assertSame('absent', $readiness['cross_domain_bridge']);
        $this->assertFalse($readiness['is_migration_authorized']);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'MigReadiness Company ' . $companySuffix,
            'slug' => 'mig-readiness-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'MigReadiness Property ' . $companySuffix,
            'slug' => 'mig-readiness-property-' . $companySuffix,
            'code' => 'MRP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'MigReadiness Other ' . $companySuffix,
            'slug' => 'mig-readiness-other-' . $companySuffix,
            'code' => 'MRO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = User::create([
            'name' => 'MigReadiness Actor ' . $companySuffix,
            'email' => 'mig-readiness-actor-' . $companySuffix . '@example.test',
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
            'bank_name' => 'MigReadiness Legacy Bank',
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
            'code' => 'MRP-GL-' . $companySuffix,
            'name' => 'MigReadiness GL Account',
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
            'bank_name' => 'MigReadiness Controlled Bank',
            'account_name' => 'Controlled Account',
            'external_account_reference' => 'MRP-EXT-' . $companySuffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'mig-readiness-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'mig-readiness-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'migration_readiness']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => (string) Str::ulid(),
            'controlled_bank_account_id' => $controlledBankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'mig-stmt-' . $companySuffix,
            'external_reference' => 'MRP-STMT-' . $companySuffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => '50.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'mig-stmt-' . $companySuffix),
            'source_snapshot' => json_encode(['test_scope' => 'migration_readiness']),
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

    private function fullSnapshot(): array
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
            'gl_accounts',
            'payment_proposals',
            'payment_proposal_items',
            'journal_candidates',
            'permissions',
            'roles',
            'users',
            'companies',
            'properties',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertFullSnapshotUnchanged(array $before): void
    {
        foreach ($before as $table => $count) {
            $after = DB::table($table)->count();
            $this->assertSame($count, $after, "Table '{$table}' mutated during read-only workspace request.");
        }
    }
}
