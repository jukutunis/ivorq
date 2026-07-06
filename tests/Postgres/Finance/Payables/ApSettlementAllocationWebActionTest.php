<?php

namespace Tests\Postgres\Finance\Payables;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\Payables\Services\ApSettlementAllocationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ApSettlementAllocationWebActionTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $allocator;
    private User $otherActor;
    private User $noAuthUser;
    private string $apAccountId;
    private string $cashAccountId;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => ApSettlementAllocationService::PERMISSION, 'guard_name' => 'web']);
    }

    public function test_unauthenticated_cannot_allocate(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $this->post(route('finance.payables.settlement-allocations.allocate'), [
            'ap_journal_entry_id' => $context['ap_journal_entry_id'],
            'payment_journal_entry_id' => $context['payment_journal_entry_id'],
        ])->assertRedirect();

        $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
    }

    public function test_actor_without_permission_receives_403(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $this->withSession($this->propertySession())
            ->actingAs($this->noAuthUser, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
            ])->assertStatus(403);

        $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
    }

    public function test_cross_property_target_fails_closed(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $this->withSession($this->otherPropertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
    }

    public function test_browser_cannot_inject_amount(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $before = DB::table('ap_settlement_allocations')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
                'amount' => '999.99',
            ])->assertRedirect(route('finance.payables.ap-grni-settlement-control'));

        $this->assertSame($before + 1, DB::table('ap_settlement_allocations')->count());

        $allocation = DB::table('ap_settlement_allocations')->first();
        $this->assertSame('125.00', $allocation->allocation_amount);
    }

    public function test_browser_cannot_inject_cross_property_journal(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $this->withSession($this->propertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => (string) Str::ulid(),
            ])->assertStatus(404);

        $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
    }

    public function test_valid_allocation_succeeds(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $this->withSession($this->propertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
            ])->assertRedirect(route('finance.payables.ap-grni-settlement-control'))
            ->assertSessionHas('success');

        $this->assertSame(1, DB::table('ap_settlement_allocations')->count());

        $allocation = DB::table('ap_settlement_allocations')->first();
        $this->assertSame($this->property->id, $allocation->property_id);
        $this->assertSame($context['ap_journal_entry_id'], $allocation->ap_journal_entry_id);
        $this->assertSame($context['payment_journal_entry_id'], $allocation->payment_journal_entry_id);
        $this->assertSame($context['payment_execution_id'], $allocation->payment_execution_id);
        $this->assertSame('125.00', $allocation->allocation_amount);
        $this->assertSame($this->allocator->id, $allocation->allocated_by);
    }

    public function test_idempotent_replay_returns_same_allocation(): void
    {
        $this->createFixtures();
        $context = $this->makePostedSettlementContext('125.00');

        $firstCount = DB::table('ap_settlement_allocations')->count();

        $this->withSession($this->propertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
            ])->assertRedirect();

        $this->assertSame($firstCount + 1, DB::table('ap_settlement_allocations')->count());

        $first = DB::table('ap_settlement_allocations')->first();

        $this->withSession($this->propertySession())
            ->actingAs($this->allocator, 'web')
            ->post(route('finance.payables.settlement-allocations.allocate'), [
                'ap_journal_entry_id' => $context['ap_journal_entry_id'],
                'payment_journal_entry_id' => $context['payment_journal_entry_id'],
            ])->assertRedirect();

        $this->assertSame(1, DB::table('ap_settlement_allocations')->count());

        $replay = DB::table('ap_settlement_allocations')->first();
        $this->assertSame($first->id, $replay->id);
    }

    private function createFixtures(): void
    {
        $companySuffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        $this->company = Company::create([
            'name' => 'AP Allocation Web Company ' . $companySuffix,
            'slug' => 'ap-alloc-web-company-' . $companySuffix,
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'AP Allocation Web Property ' . $companySuffix,
            'slug' => 'ap-alloc-web-property-' . $companySuffix,
            'code' => 'AAW' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'AP Allocation Web Other Property ' . $companySuffix,
            'slug' => 'ap-alloc-web-other-property-' . $companySuffix,
            'code' => 'AAO' . $companySuffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);

        $this->allocator = $this->user('AP Alloc Web Allocator ' . $companySuffix, 'ap-alloc-web-allocator-' . $companySuffix . '@example.test');
        $this->allocator->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->allocator->properties()->attach($this->otherProperty->id, [
            'is_default' => false, 'status' => 'active', 'joined_at' => now(),
        ]);
        $this->allocator->givePermissionTo(ApSettlementAllocationService::PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->otherActor = $this->user('AP Alloc Web Other ' . $companySuffix, 'ap-alloc-web-other-' . $companySuffix . '@example.test');
        $this->otherActor->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $this->noAuthUser = $this->user('AP Alloc Web NoAuth ' . $companySuffix, 'ap-alloc-web-noauth-' . $companySuffix . '@example.test');
        $this->noAuthUser->properties()->attach($this->property->id, [
            'is_default' => true, 'status' => 'active', 'joined_at' => now(),
        ]);

        $seq = (int) (microtime(true) * 1000) % 100000;
        $this->apAccountId = $this->makeAccount('AP-ALLOC-WEB-' . $seq++, 'Liability', 'CurrentLiability', 'Credit', false);
        $this->cashAccountId = $this->makeAccount('CASH-ALLOC-WEB-' . $seq++, 'Asset', 'CurrentAsset', 'Debit', true);
    }

    private function makePostedSettlementContext(string $amount): array
    {
        $timestamp = now();
        $vendorId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $sourceCandidateId = (string) Str::ulid();
        $apJournalEntryId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $paymentJournalEntryId = (string) Str::ulid();
        $suffix = substr(hash('sha256', (string) microtime(true)), 0, 6);

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source candidate for web allocation',
            'approved_by' => $this->allocator->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'ap_settlement_allocation_web']),
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $apJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'AP-ALLOC-WEB-' . $suffix,
            'description' => 'Posted AP liability for web allocation',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'Payables',
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'journal_candidate_id' => $sourceCandidateId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit AP liability web',
                'created_by' => $this->allocator->id,
                'updated_by' => $this->allocator->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->makeAccount('INV-ALLOC-WEB-' . $suffix, 'Asset', 'CurrentAsset', 'Debit', false),
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit source inventory web',
                'created_by' => $this->allocator->id,
                'updated_by' => $this->allocator->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $apJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->allocator->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->allocator->id,
                'updated_at' => $timestamp,
            ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'ALLOC-WEB-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => $amount,
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
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
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'ap_settlement_allocation_web']),
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_executions')->insert([
            'id' => $paymentExecutionId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'payment_proposal_id' => $proposalId,
            'payment_proposal_item_id' => $proposalItemId,
            'source_journal_entry_id' => $apJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => (string) Str::ulid(),
            'operational_gl_account_id' => $this->cashAccountId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'executed_by' => $this->allocator->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'ap_settlement_allocation_web']),
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('journal_candidates')->insert([
            'id' => $paymentCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted supplier payment candidate web',
            'approved_by' => $this->allocator->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'ap_settlement_allocation_web']),
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $paymentJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'PAY-ALLOC-WEB-' . $suffix,
            'description' => 'Posted supplier payment for web allocation',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'posted_by' => null,
            'posted_at' => null,
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP liability web',
                'created_by' => $this->allocator->id,
                'updated_by' => $this->allocator->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit cash control web',
                'created_by' => $this->allocator->id,
                'updated_by' => $this->allocator->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->allocator->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->allocator->id,
                'updated_at' => $timestamp,
            ]);

        return [
            'vendor_id' => $vendorId,
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
        ];
    }

    private function makeAccount(string $code, string $type, string $category, string $normalBalance, bool $cashEquivalent): string
    {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => $code . ' Account',
            'normal_balance' => $normalBalance,
            'account_type' => $type,
            'account_category' => $category,
            'is_active' => true,
            'is_cash_equivalent' => $cashEquivalent,
            'created_by' => $this->allocator->id,
            'updated_by' => $this->allocator->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
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
}
