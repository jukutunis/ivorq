<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\Payables\Services\ApSettlementAllocationService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class ApSettlementAllocationFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private string $apAccountId;
    private string $cashAccountId;
    private ApSettlementAllocationService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);
        $this->apAccountId = $this->makeAccount('AP-ALLOC-' . $this->sequence++, 'Liability', 'CurrentLiability', 'Credit', false);
        $this->cashAccountId = $this->makeAccount('CASH-ALLOC-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', true);

        Permission::firstOrCreate([
            'name' => ApSettlementAllocationService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(ApSettlementAllocationService::PERMISSION);

        $this->service = app(ApSettlementAllocationService::class);
    }

    public function test_ap_settlement_allocation_is_append_only_and_idempotent(): void
    {
        $context = $this->makePostedSettlementContext('125.00');
        $before = $this->controlledSnapshot();

        $allocation = $this->service->allocate(
            $context['ap_journal_entry_id'],
            $context['payment_journal_entry_id'],
            '125.00',
            $this->actor
        );

        $this->assertSame($this->property->id, $allocation->property_id);
        $this->assertSame($context['vendor_id'], $allocation->vendor_id);
        $this->assertSame('IDR', $allocation->currency_code);
        $this->assertSame($context['ap_journal_entry_id'], $allocation->ap_journal_entry_id);
        $this->assertSame($context['payment_journal_entry_id'], $allocation->payment_journal_entry_id);
        $this->assertSame($context['payment_execution_id'], $allocation->payment_execution_id);
        $this->assertSame('125.00', (string) $allocation->allocation_amount);
        $this->assertSame($this->actor->id, $allocation->allocated_by);
        $this->assertNotNull($allocation->allocated_at);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'ap_settlement_allocations' => 1,
        ]);

        $replay = $this->service->allocate(
            $context['ap_journal_entry_id'],
            $context['payment_journal_entry_id'],
            '125.00',
            $this->actor
        );
        $this->assertSame($allocation->id, $replay->id);
    }

    public function test_ap_settlement_allocation_fails_closed_for_invalid_actor_and_over_allocation(): void
    {
        $context = $this->makePostedSettlementContext('125.00');
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);

        try {
            $this->service->allocate(
                $context['ap_journal_entry_id'],
                $context['payment_journal_entry_id'],
                '125.00',
                $unauthorized
            );
            $this->fail('Unauthorized AP settlement allocation must fail closed.');
        } catch (AuthorizationException) {
            $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
        }

        try {
            $this->service->allocate(
                $context['ap_journal_entry_id'],
                $context['payment_journal_entry_id'],
                '126.00',
                $this->actor
            );
            $this->fail('Over-allocation must fail closed.');
        } catch (DomainException) {
            $this->assertSame(0, DB::table('ap_settlement_allocations')->count());
        }
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
        $suffix = $this->sequence++;

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'AP source candidate for settlement allocation',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'ap_settlement_allocation']),
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
            'reference' => 'AP-ALLOC-' . $suffix,
            'description' => 'Posted AP liability for settlement allocation',
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
                'account_id' => $this->makeAccount('INV-ALLOC-' . $this->sequence++, 'Asset', 'CurrentAsset', 'Debit', false),
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit source inventory',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $apJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit AP liability',
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
            'proposal_number' => 'ALLOC-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $apJournalEntryId),
            'total_amount' => $amount,
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
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'ap_settlement_allocation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
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
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'ap_settlement_allocation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
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
            'description' => 'Posted supplier payment candidate',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'ap_settlement_allocation']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $paymentJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'PAY-ALLOC-' . $suffix,
            'description' => 'Posted supplier payment for allocation',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
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
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $this->apAccountId,
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP liability',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
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
                'memo' => 'Credit cash control',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        DB::table('gl_journal_entries')
            ->where('id', $paymentJournalEntryId)
            ->update([
                'posting_date' => '2026-07-01',
                'status' => JournalStatusEnum::Posted->value,
                'posted_by' => $this->actor->id,
                'posted_at' => $timestamp,
                'updated_by' => $this->actor->id,
                'updated_at' => $timestamp,
            ]);

        return [
            'vendor_id' => $vendorId,
            'ap_journal_entry_id' => $apJournalEntryId,
            'payment_execution_id' => $paymentExecutionId,
            'payment_journal_entry_id' => $paymentJournalEntryId,
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'ap_settlement_allocations',
            'payment_executions',
            'payment_proposals',
            'payment_proposal_items',
            'gl_journal_entries',
            'gl_journal_entry_lines',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::table($table)->count();
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
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
            'created_by' => $this->actor?->id,
            'updated_by' => $this->actor?->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function attachActorToProperty(User $actor, Property $property): void
    {
        $actor->properties()->syncWithoutDetaching([
            $property->id => [
                'is_default' => true,
                'status' => 'active',
                'joined_at' => now(),
            ],
        ]);
    }

    private function makeProperty(): Property
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $timestamp = now();
        $suffix = $this->sequence++;

        DB::table('companies')->insert([
            'id' => $companyId,
            'name' => 'AP Settlement Allocation Company ' . $suffix,
            'slug' => 'ap-settlement-allocation-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'AP Settlement Allocation Property ' . $suffix,
            'slug' => 'ap-settlement-allocation-property-' . $suffix,
            'code' => 'AS' . $suffix,
            'timezone' => 'UTC',
            'currency' => 'IDR',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return Property::query()->findOrFail($propertyId);
    }

    private function makeUser(): User
    {
        $userId = (string) Str::ulid();
        $suffix = $this->sequence++;
        $timestamp = now();

        DB::table('users')->insert([
            'id' => $userId,
            'is_system_admin' => false,
            'name' => 'AP Settlement Allocation User ' . $suffix,
            'email' => 'ap-settlement-allocation-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
