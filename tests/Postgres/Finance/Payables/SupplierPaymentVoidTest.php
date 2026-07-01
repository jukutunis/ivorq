<?php

namespace Tests\Postgres\Finance\Payables;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Services\JournalCandidateReviewService;
use Modules\Finance\GeneralLedger\Services\SupplierPaymentJournalCandidateService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Services\PaymentExecutionVoidService;
use RuntimeException;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class SupplierPaymentVoidTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private PaymentExecutionVoidService $voidService;
    private JournalCandidateReviewService $reviewService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        foreach ([
            PaymentExecutionVoidService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate([
                'name' => $permission,
                'guard_name' => 'web',
            ]);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo([
            PaymentExecutionVoidService::PERMISSION,
            JournalCandidateReviewService::PERMISSION,
            SupplierPaymentJournalCandidateService::PERMISSION,
        ]);

        $this->voidService = app(PaymentExecutionVoidService::class);
        $this->reviewService = app(JournalCandidateReviewService::class);
    }

    public function test_pre_post_void_records_evidence_and_blocks_later_candidate_review_without_source_mutation(): void
    {
        $executionId = $this->makePaymentExecution('1000.00');
        $candidateId = $this->makePaymentCandidate($executionId, JournalCandidateStatusEnum::PENDING_REVIEW);
        $before = $this->controlledSnapshot();

        $void = $this->voidService->void(
            $executionId,
            'Source payment stopped before posting.',
            $this->actor
        );

        $this->assertSame($executionId, $void->payment_execution_id);
        $this->assertSame($this->property->id, $void->property_id);
        $this->assertSame('1000.00', (string) $void->source_amount);
        $this->assertSame('Source payment stopped before posting.', $void->void_reason);
        $this->assertSame($this->actor->id, $void->voided_by);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'payment_execution_void_evidence' => 1,
        ]);

        $voidSnapshot = $this->voidSnapshot($void->id);
        $replay = $this->voidService->void(
            $executionId,
            'Source payment stopped before posting.',
            $this->actor
        );

        $this->assertSame($void->id, $replay->id);
        $this->assertSame($voidSnapshot, $this->voidSnapshot($void->id));

        $beforeReview = $this->controlledSnapshot();
        try {
            $this->reviewService->approve($candidateId, $this->actor->id);
            $this->fail('Voided supplier payment candidate review must fail closed.');
        } catch (RuntimeException) {
            $this->assertControlledSnapshotUnchanged($beforeReview);
        }

        $beforeConflict = $this->controlledSnapshot();
        try {
            $this->voidService->void($executionId, 'Different void reason.', $this->actor);
            $this->fail('Conflicting VOID replay must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeConflict);
        }
    }

    public function test_pre_post_void_blocks_approved_candidate_and_unauthorized_actor(): void
    {
        $approvedExecutionId = $this->makePaymentExecution('900.00');
        $this->makePaymentCandidate($approvedExecutionId, JournalCandidateStatusEnum::APPROVED);
        $beforeApproved = $this->controlledSnapshot();

        try {
            $this->voidService->void($approvedExecutionId, 'Attempt after approval.', $this->actor);
            $this->fail('PaymentExecution with approved payment candidate must not be voided.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeApproved);
        }

        $pendingExecutionId = $this->makePaymentExecution('800.00');
        $this->makePaymentCandidate($pendingExecutionId, JournalCandidateStatusEnum::PENDING_REVIEW);
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $beforeUnauthorized = $this->controlledSnapshot();

        try {
            $this->voidService->void($pendingExecutionId, 'Unauthorized void.', $unauthorized);
            $this->fail('Unauthorized PaymentExecution VOID must fail closed.');
        } catch (AuthorizationException) {
            $this->assertControlledSnapshotUnchanged($beforeUnauthorized);
        }
    }

    private function makePaymentExecution(string $amount): string
    {
        $timestamp = now();
        $proposalId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $executionId = (string) Str::ulid();
        $sourceJournalEntryId = (string) Str::ulid();
        $sourceJournalCandidateId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $suffix = $this->sequence++;

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'VOID-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', 'void-proposal-' . $suffix),
            'total_amount' => $amount,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $itemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceJournalEntryId,
            'source_journal_candidate_id' => $sourceJournalCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'supplier_payment_void']),
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
            'payment_proposal_item_id' => $itemId,
            'source_journal_entry_id' => $sourceJournalEntryId,
            'source_journal_candidate_id' => $sourceJournalCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => (string) Str::ulid(),
            'operational_gl_account_id' => (string) Str::ulid(),
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'supplier_payment_void']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $executionId;
    }

    private function makePaymentCandidate(string $executionId, JournalCandidateStatusEnum $status): string
    {
        $candidateId = (string) Str::ulid();
        $timestamp = now();

        DB::table('journal_candidates')->insert([
            'id' => $candidateId,
            'property_id' => $this->property->id,
            'source_type' => 'PaymentExecution',
            'source_id' => $executionId,
            'posting_event' => PaymentExecutionVoidService::PAYMENT_POSTING_EVENT,
            'status' => $status->value,
            'candidate_date' => '2026-07-01',
            'description' => 'Supplier payment void test candidate',
            'approved_by' => $status === JournalCandidateStatusEnum::APPROVED ? $this->actor->id : null,
            'approved_at' => $status === JournalCandidateStatusEnum::APPROVED ? $timestamp : null,
            'metadata' => json_encode(['test_scope' => 'supplier_payment_void']),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $candidateId;
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'payment_execution_void_evidence',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'gl_ledger_balances',
        ];

        $snapshot = [];
        foreach ($tables as $table) {
            $snapshot[$table] = DB::getSchemaBuilder()->hasTable($table)
                ? DB::table($table)->count()
                : 0;
        }

        return $snapshot;
    }

    private function assertControlledSnapshotUnchanged(array $before): void
    {
        $this->assertSame($before, $this->controlledSnapshot());
    }

    private function assertControlledSnapshotUnchangedExcept(array $before, array $allowedDeltas): void
    {
        $after = $this->controlledSnapshot();

        foreach ($before as $table => $count) {
            $this->assertSame($count + ($allowedDeltas[$table] ?? 0), $after[$table], $table);
        }
    }

    private function voidSnapshot(string $voidId): array
    {
        return (array) DB::table('payment_execution_void_evidence')
            ->where('id', $voidId)
            ->first([
                'payment_execution_id',
                'property_id',
                'vendor_id',
                'payment_proposal_id',
                'payment_proposal_item_id',
                'source_journal_entry_id',
                'source_journal_candidate_id',
                'supplier_invoice_id',
                'operational_gl_account_id',
                'currency_code',
                'source_amount',
                'void_reason',
                'voided_by',
                'voided_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
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
            'name' => 'Supplier Payment Void Company ' . $suffix,
            'slug' => 'supplier-payment-void-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Supplier Payment Void Property ' . $suffix,
            'slug' => 'supplier-payment-void-property-' . $suffix,
            'code' => 'PV' . $suffix,
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
            'name' => 'Supplier Payment Void User ' . $suffix,
            'email' => 'supplier-payment-void-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
