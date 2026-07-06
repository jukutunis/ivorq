<?php

namespace Tests\Postgres\Finance\GeneralCashier;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\Payables\Enums\PaymentProposalStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class CashExecutionContextProjectionTest extends PostgresTestCase
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

    public function test_unauthenticated_actor_cannot_access_cashbook_evidence(): void
    {
        $this->createFixtures();

        $this->get(route('finance.payables.cashbook-evidence.index'))
            ->assertRedirect();
    }

    public function test_cash_execution_context_is_property_isolated(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->component('Ivorq/Finance/CashbookEvidenceWorkspace'));

        $props = $response->inertiaProps();
        $context = $props['cash_execution_context'] ?? null;
        $this->assertNotNull($context);

        $eligibleItems = $context['eligible_items'] ?? [];
        foreach ($eligibleItems as $item) {
            $this->assertNotEmpty($item['amount']);
            $this->assertNotEmpty($item['currency_code']);
        }

        $sessions = $context['cash_sessions'] ?? [];
        foreach ($sessions as $session) {
            $this->assertSame('open', $session['status']);
        }
    }

    public function test_cross_property_actor_sees_different_context(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->otherPropertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $context = $props['cash_execution_context'] ?? null;
        $this->assertNotNull($context);

        $eligibleItems = $context['eligible_items'] ?? [];
        $this->assertCount(0, $eligibleItems);
    }

    public function test_empty_state_when_no_cash_instruments(): void
    {
        $this->createFixturesWithoutInstruments();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $context = $props['cash_execution_context'] ?? null;
        $this->assertNotNull($context);

        $instruments = $context['cash_instruments'] ?? [];
        $this->assertCount(0, $instruments);
    }

    public function test_cash_execution_context_shows_no_execute_action(): void
    {
        $this->createFixtures();

        $response = $this->withSession($this->propertySession())
            ->actingAs($this->actor, 'web')
            ->get(route('finance.payables.cashbook-evidence.index'))
            ->assertOk();

        $props = $response->inertiaProps();
        $context = $props['cash_execution_context'] ?? null;
        $this->assertNotNull($context);

        $this->assertArrayNotHasKey('can_execute', $context);
        $this->assertArrayNotHasKey('execute_permission', $context);
    }

    public function test_cash_context_read_only_no_domain_mutation(): void
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
            'name' => 'Cash Context Company ' . $companySuffix,
            'slug' => 'cash-context-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Context Property ' . $companySuffix,
            'slug' => 'cash-context-property-' . $companySuffix,
            'code' => 'CCP' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash Context Other ' . $companySuffix,
            'slug' => 'cash-context-other-' . $companySuffix,
            'code' => 'CCO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = $this->user('Cash Context Actor ' . $companySuffix, 'cash-context-actor-' . $companySuffix . '@example.test');
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->actor->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);

        $timestamp = now();

        DB::table('cashier_sessions')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => 'open',
            'opened_by' => $this->actor->id,
            'opened_at' => $timestamp,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $instrumentAccountId = (string) Str::ulid();
        DB::table('gl_accounts')->insert([
            'id' => $instrumentAccountId,
            'property_id' => $this->property->id,
            'code' => 'CASH-INST-' . $companySuffix,
            'name' => 'Cash Instrument Account',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_payment_instruments')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'name' => 'Cash Drawer ' . $companySuffix,
            'type' => 'CASH',
            'operational_gl_account_id' => $instrumentAccountId,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $vendorId = (string) Str::ulid();
        DB::table('vendor_categories')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $this->property->id,
            'category_code' => 'VEND-CASH-' . $companySuffix,
            'name' => 'Test Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $this->property->id,
            'company_id' => $this->company->id,
            'vendor_category_id' => DB::table('vendor_categories')->first()->id,
            'vendor_code' => 'V-CASH-' . $companySuffix,
            'name' => 'Vendor ' . $companySuffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $proposalId = (string) Str::ulid();
        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'CASH-PROP-' . $companySuffix,
            'currency_code' => 'IDR',
            'status' => PaymentProposalStatusEnum::APPROVED->value,
            'source_fingerprint' => hash('sha256', 'test-' . $companySuffix),
            'total_amount' => '100.00',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        $sourceCandidateId = (string) Str::ulid();
        $sourceJournalId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source for cash context',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'cash_context_projection']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $sourceJournalId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => '2026-07-01',
            'reference' => 'CASH-JRNL-' . $companySuffix,
            'description' => 'Posted AP liability for cash context',
            'status' => 'Posted',
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => $this->actor->id,
            'posted_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => (string) Str::ulid(),
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceJournalId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => '100.00',
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'cash_context_projection']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);
    }

    private function createFixturesWithoutInstruments(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'Cash No Inst Company ' . $companySuffix,
            'slug' => 'cash-no-inst-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Cash No Inst Property ' . $companySuffix,
            'slug' => 'cash-no-inst-property-' . $companySuffix,
            'code' => 'CNI' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->actor = $this->user('Cash No Inst Actor ' . $companySuffix, 'cash-no-inst-actor-' . $companySuffix . '@example.test');
        $this->actor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
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
            'payment_executions',
            'payment_proposals',
            'payment_proposal_items',
            'gl_journal_entries',
            'cashier_sessions',
            'cashier_payment_instruments',
            'cashbook_transactions',
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
