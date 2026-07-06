<?php

namespace Tests\Postgres\Finance\Banking;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Banking\Enums\BankPaymentReconciliationStatusEnum;
use Modules\Finance\Banking\Enums\ControlledBankStatementLineDirectionEnum;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Finance\Banking\Services\ManualBankReconciliationService;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class BankReconciliationWorkspaceTest extends PostgresTestCase
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

    public function test_property_isolation_for_reconciliation_records(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $recs = $props['reconciliation_evidence'] ?? [];
        $this->assertGreaterThan(0, count($recs));

        $crossResponse = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $crossProps = $crossResponse->inertiaProps();
        $crossRecs = $crossProps['reconciliation_evidence'] ?? [];
        $this->assertCount(0, $crossRecs);
    }

    public function test_no_mutation_when_viewing_reconciliation_evidence(): void
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

    public function test_no_browser_query_injection(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index') . '?property_id=' . $this->otherProperty->id)
            ->assertOk();

        $props = $response->inertiaProps();
        $recs = $props['reconciliation_evidence'] ?? [];
        $this->assertGreaterThan(0, count($recs));
    }

    public function test_controlled_empty_state(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $company = Company::create([
            'name' => 'Empty Recon Company ' . $companySuffix,
            'slug' => 'empty-recon-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $property = Property::create([
            'company_id' => $company->id,
            'name' => 'Empty Recon Property ' . $companySuffix,
            'slug' => 'empty-recon-property-' . $companySuffix,
            'code' => 'ERP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($property->id);
        setPermissionsTeamId($property->id);

        $user = User::create([
            'name' => 'Empty Recon User ' . $companySuffix,
            'email' => 'empty-recon-user-' . $companySuffix . '@example.test',
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
        $this->assertCount(0, $props['reconciliation_evidence'] ?? []);
    }

    public function test_reconciliation_capability_is_server_owned(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.banking.operations.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $permissions = $props['permissions'] ?? [];

        $this->assertIsBool($permissions['can_reconcile_bank'] ?? null);
    }

    public function test_banking_ownership_preserved(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
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

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Recon WS Company ' . $companySuffix,
            'slug' => 'recon-ws-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Recon WS Property ' . $companySuffix,
            'slug' => 'recon-ws-property-' . $companySuffix,
            'code' => 'RWS' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Recon WS Other ' . $companySuffix,
            'slug' => 'recon-ws-other-' . $companySuffix,
            'code' => 'RWO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = User::create([
            'name' => 'Recon WS Actor ' . $companySuffix,
            'email' => 'recon-ws-actor-' . $companySuffix . '@example.test',
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
        $glAccountId = (string) Str::ulid();
        $bankAccountId = (string) Str::ulid();
        $statementLineId = (string) Str::ulid();
        $reconciliationId = (string) Str::ulid();
        $executionId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $suffix = $companySuffix;

        DB::table('gl_accounts')->insert([
            'id' => $glAccountId,
            'property_id' => $this->property->id,
            'code' => 'RECON-WS-GL-' . $suffix,
            'name' => 'Recon WS GL Account',
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
            'operational_gl_account_id' => $glAccountId,
            'bank_name' => 'Recon WS Test Bank',
            'account_name' => 'Recon WS Operating Account',
            'external_account_reference' => 'RECON-WS-EXT-' . $suffix,
            'currency_code' => 'IDR',
            'is_active' => true,
            'source_reference' => 'recon-ws-test',
            'registered_by' => $this->actor->id,
            'registered_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'recon-ws-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'recon_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('controlled_bank_statement_lines')->insert([
            'id' => $statementLineId,
            'controlled_bank_account_id' => $bankAccountId,
            'property_id' => $this->property->id,
            'source_reference' => 'recon-ws-stmt-' . $suffix,
            'external_reference' => 'RECON-WS-STMT-' . $suffix,
            'statement_date' => '2026-07-01',
            'direction' => ControlledBankStatementLineDirectionEnum::OUTFLOW->value,
            'amount' => '75.00',
            'currency_code' => 'IDR',
            'vendor_reference' => null,
            'recorded_by' => $this->actor->id,
            'recorded_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'recon-ws-stmt-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'recon_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $vendorCategoryId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => $vendorCategoryId,
            'property_id' => $this->property->id,
            'category_code' => 'VC-' . $suffix,
            'name' => 'Recon WS Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $this->property->id,
            'company_id' => $this->company->id,
            'vendor_category_id' => $vendorCategoryId,
            'vendor_code' => 'V-' . $suffix,
            'name' => 'Recon WS Vendor ' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source for recon ws',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'recon_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $apJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-JRNL-' . $suffix,
            'description' => 'Posted AP for recon ws',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $glAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => '75.00',
                'memo' => 'Credit AP',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $glAccountId,
                'debit_amount' => '75.00',
                'credit_amount' => '0.00',
                'memo' => 'Debit inventory',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->actor->id,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'RWS-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => PaymentProposalStatusEnum::APPROVED->value,
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => '75.00',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => '75.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'recon_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_executions')->insert([
            'id' => $executionId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => (string) Str::ulid(),
            'operational_gl_account_id' => $glAccountId,
            'controlled_bank_account_id' => $bankAccountId,
            'controlled_bank_statement_line_id' => $statementLineId,
            'currency_code' => 'IDR',
            'source_amount' => '75.00',
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'recon_workspace']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('bank_payment_reconciliations')->insert([
            'id' => $reconciliationId,
            'property_id' => $this->property->id,
            'controlled_bank_account_id' => $bankAccountId,
            'controlled_bank_statement_line_id' => $statementLineId,
            'payment_execution_id' => $executionId,
            'posted_journal_entry_id' => (string) Str::ulid(),
            'currency_code' => 'IDR',
            'payment_amount' => '75.00',
            'statement_amount' => '75.00',
            'difference_amount' => '0.00',
            'status' => BankPaymentReconciliationStatusEnum::RECONCILED->value,
            'reconciled_by' => $this->actor->id,
            'reconciled_at' => $timestamp,
            'source_identity_hash' => hash('sha256', 'committed-recon-ws-' . $suffix),
            'source_snapshot' => json_encode(['test_scope' => 'recon_workspace']),
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
            'bank_payment_reconciliations',
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
