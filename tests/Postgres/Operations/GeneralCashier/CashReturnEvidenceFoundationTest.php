<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Enums\JournalStatusEnum;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierPaymentInstrumentTypeEnum;
use Modules\Operations\GeneralCashier\Services\CashReturnEvidenceService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashReturnEvidenceFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private CashReturnEvidenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        Permission::firstOrCreate([
            'name' => CashReturnEvidenceService::PERMISSION,
            'guard_name' => 'web',
        ]);

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(CashReturnEvidenceService::PERMISSION);

        $this->service = app(CashReturnEvidenceService::class);
    }

    public function test_cash_return_evidence_records_actual_return_without_source_or_cashbook_mutation(): void
    {
        $payment = $this->makePostedCashSupplierPayment('1000.00');
        $before = $this->controlledSnapshot();

        $return = $this->service->recordCashReturn(
            $payment['posted_journal_entry_id'],
            'OBSERVED-CASH-RETURN-001',
            '2026-07-05',
            $this->actor
        );

        $this->assertSame($payment['payment_execution_id'], $return->payment_execution_id);
        $this->assertSame($payment['posted_journal_entry_id'], $return->posted_journal_entry_id);
        $this->assertSame($this->property->id, $return->property_id);
        $this->assertSame($payment['vendor_id'], $return->vendor_id);
        $this->assertSame($payment['cash_account_id'], $return->operational_gl_account_id);
        $this->assertSame('IDR', $return->currency_code);
        $this->assertSame('1000.00', (string) $return->return_amount);
        $this->assertSame('2026-07-05', $return->observed_return_date->toDateString());
        $this->assertSame('OBSERVED-CASH-RETURN-001', $return->source_reference);
        $this->assertSame($this->actor->id, $return->recorded_by);

        $this->assertControlledSnapshotUnchangedExcept($before, [
            'cash_return_evidence' => 1,
        ]);

        $returnSnapshot = $this->returnSnapshot($return->id);
        $replay = $this->service->recordCashReturn(
            $payment['posted_journal_entry_id'],
            'OBSERVED-CASH-RETURN-001',
            '2026-07-05',
            $this->actor
        );

        $this->assertSame($return->id, $replay->id);
        $this->assertSame($returnSnapshot, $this->returnSnapshot($return->id));

        $beforeConflict = $this->controlledSnapshot();
        try {
            $this->service->recordCashReturn(
                $payment['posted_journal_entry_id'],
                'OBSERVED-CASH-RETURN-CONFLICT',
                '2026-07-05',
                $this->actor
            );
            $this->fail('Conflicting Cash Return evidence replay must fail controlled.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeConflict);
        }
    }

    public function test_cash_return_evidence_requires_cash_payment_source_and_authorized_actor(): void
    {
        $bankLikePayment = $this->makePostedCashSupplierPayment(
            '750.00',
            CashierPaymentInstrumentTypeEnum::BANK
        );
        $beforeBankLike = $this->controlledSnapshot();

        try {
            $this->service->recordCashReturn(
                $bankLikePayment['posted_journal_entry_id'],
                'BANK-LIKE-RETURN',
                '2026-07-06',
                $this->actor
            );
            $this->fail('Cash Return evidence must reject non-CASH instrument evidence.');
        } catch (DomainException) {
            $this->assertControlledSnapshotUnchanged($beforeBankLike);
        }

        $payment = $this->makePostedCashSupplierPayment('800.00');
        $unauthorized = $this->makeUser();
        $this->attachActorToProperty($unauthorized, $this->property);
        $beforeUnauthorized = $this->controlledSnapshot();

        try {
            $this->service->recordCashReturn(
                $payment['posted_journal_entry_id'],
                'UNAUTHORIZED-CASH-RETURN',
                '2026-07-06',
                $unauthorized
            );
            $this->fail('Unauthorized Cash Return evidence recording must fail closed.');
        } catch (AuthorizationException) {
            $this->assertControlledSnapshotUnchanged($beforeUnauthorized);
        }
    }

    /**
     * @return array{payment_execution_id: string, posted_journal_entry_id: string, vendor_id: string, cash_account_id: string}
     */
    private function makePostedCashSupplierPayment(
        string $amount,
        CashierPaymentInstrumentTypeEnum $instrumentType = CashierPaymentInstrumentTypeEnum::CASH,
        JournalStatusEnum $journalStatus = JournalStatusEnum::Posted
    ): array {
        $timestamp = now();
        $suffix = $this->sequence++;
        $vendorId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $itemId = (string) Str::ulid();
        $sourceApJournalEntryId = (string) Str::ulid();
        $sourceApCandidateId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $instrumentId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $paymentJournalEntryId = (string) Str::ulid();
        $cashAccountId = $this->makeAccount('CASH-RETURN-' . $suffix);

        DB::table('cashier_payment_instruments')->insert([
            'id' => $instrumentId,
            'property_id' => $this->property->id,
            'name' => 'Return Instrument ' . $suffix,
            'type' => $instrumentType->value,
            'operational_gl_account_id' => $cashAccountId,
            'is_active' => true,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'CASH-RETURN-PROP-' . $suffix,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', 'cash-return-proposal-' . $suffix),
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
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceApCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'is_active' => true,
            'source_snapshot' => json_encode(['test_scope' => 'cash_return_evidence']),
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
            'payment_proposal_item_id' => $itemId,
            'source_journal_entry_id' => $sourceApJournalEntryId,
            'source_journal_candidate_id' => $sourceApCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => (string) Str::ulid(),
            'cashier_payment_instrument_id' => $instrumentId,
            'operational_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR',
            'source_amount' => $amount,
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['test_scope' => 'cash_return_evidence']),
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
            'posting_event' => CashReturnEvidenceService::POSTING_EVENT,
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Posted cash payment candidate fixture',
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['test_scope' => 'cash_return_evidence']),
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
            'reference' => 'CASH-RETURN-JOURNAL-' . $suffix,
            'description' => 'Posted cash supplier payment fixture',
            'status' => JournalStatusEnum::Draft->value,
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => CashReturnEvidenceService::POSTING_EVENT,
            'draft_finalization_authorized_by' => $this->actor->id,
            'draft_finalization_authorized_at' => $timestamp,
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
                'account_id' => (string) Str::ulid(),
                'debit_amount' => $amount,
                'credit_amount' => '0.00',
                'memo' => 'Debit AP liability fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $paymentJournalEntryId,
                'account_id' => $cashAccountId,
                'debit_amount' => '0.00',
                'credit_amount' => $amount,
                'memo' => 'Credit cash control fixture',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        if ($journalStatus === JournalStatusEnum::Posted) {
            DB::table('gl_journal_entries')
                ->where('id', $paymentJournalEntryId)
                ->update([
                    'status' => JournalStatusEnum::Posted->value,
                    'posting_date' => '2026-07-01',
                    'posted_by' => $this->actor->id,
                    'posted_at' => $timestamp,
                    'updated_by' => $this->actor->id,
                    'updated_at' => $timestamp,
                ]);
        }

        return [
            'payment_execution_id' => $paymentExecutionId,
            'posted_journal_entry_id' => $paymentJournalEntryId,
            'vendor_id' => $vendorId,
            'cash_account_id' => $cashAccountId,
        ];
    }

    private function controlledSnapshot(): array
    {
        $tables = [
            'cash_return_evidence',
            'payment_executions',
            'cashbook_transactions',
            'cash_count_evidence',
            'cash_reconciliations',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'journal_candidates',
            'payment_proposals',
            'payment_proposal_items',
            'controlled_bank_statement_lines',
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

    private function returnSnapshot(string $returnId): array
    {
        return (array) DB::table('cash_return_evidence')
            ->where('id', $returnId)
            ->first([
                'payment_execution_id',
                'posted_journal_entry_id',
                'property_id',
                'vendor_id',
                'operational_gl_account_id',
                'currency_code',
                'return_amount',
                'observed_return_date',
                'source_reference',
                'recorded_by',
                'recorded_at',
                'source_identity_hash',
                'source_snapshot',
                'created_by',
                'created_at',
            ]);
    }

    private function makeAccount(string $code): string
    {
        $account = Account::create([
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => 'Cash Return Control',
            'normal_balance' => 'Debit',
            'account_type' => 'Asset',
            'account_category' => 'CurrentAsset',
            'is_active' => true,
            'is_cash_equivalent' => true,
            'created_by' => $this->actor?->id,
            'updated_by' => $this->actor?->id,
        ]);

        return $account->id;
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
            'name' => 'Cash Return Company ' . $suffix,
            'slug' => 'cash-return-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Cash Return Property ' . $suffix,
            'slug' => 'cash-return-property-' . $suffix,
            'code' => 'RT' . $suffix,
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
            'name' => 'Cash Return User ' . $suffix,
            'email' => 'cash-return-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }
}
