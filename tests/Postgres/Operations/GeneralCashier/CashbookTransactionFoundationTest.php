<?php

namespace Tests\Postgres\Operations\GeneralCashier;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Finance\GeneralLedger\Services\JournalEntryControlledPostingService;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class CashbookTransactionFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private int $sequence = 1;
    private Property $property;
    private User $actor;
    private JournalEntryControlledPostingService $postingService;

    protected function setUp(): void
    {
        parent::setUp();

        $this->property = $this->makeProperty();
        $this->actor = $this->makeUser();
        $this->attachActorToProperty($this->actor, $this->property);

        Permission::firstOrCreate([
            'name' => JournalEntryControlledPostingService::PERMISSION,
            'guard_name' => 'web',
        ]);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->actor->givePermissionTo(JournalEntryControlledPostingService::PERMISSION);

        $this->postingService = app(JournalEntryControlledPostingService::class);
    }

    public function test_controlled_cash_supplier_payment_posting_creates_one_immutable_cashbook_transaction(): void
    {
        $context = $this->makeCashSupplierPaymentDraftContext();
        $this->openPostingControls($this->property, '2026-07-01');

        $posted = $this->postingService->post($context['draft_journal_entry_id'], $this->actor->id);

        $this->assertSame('Posted', $posted->status->value);
        $this->assertSame(1, DB::table('cashbook_transactions')->count());

        $cashbook = (array) DB::table('cashbook_transactions')->first();

        $this->assertSame($this->property->id, $cashbook['property_id']);
        $this->assertSame($context['cash_account_id'], $cashbook['operational_gl_account_id']);
        $this->assertSame('IDR', $cashbook['currency_code']);
        $this->assertSame('125.00', number_format((float) $cashbook['amount'], 2, '.', ''));
        $this->assertSame('OUTFLOW', $cashbook['direction']);
        $this->assertSame('2026-07-01', $cashbook['posted_business_date']);
        $this->assertSame($context['draft_journal_entry_id'], $cashbook['journal_entry_id']);
        $this->assertSame($context['payment_execution_id'], $cashbook['payment_execution_id']);
        $this->assertSame('GeneralLedger', $cashbook['source_module']);
        $this->assertSame('JournalEntry', $cashbook['source_type']);
        $this->assertSame($context['draft_journal_entry_id'], $cashbook['source_id']);
        $this->assertSame('SupplierPaymentCashDisbursement', $cashbook['source_event']);
        $this->assertSame($this->actor->id, $cashbook['projected_by']);
        $this->assertNotEmpty($cashbook['source_identity_hash']);

        $snapshot = json_decode($cashbook['source_snapshot'], true, 512, JSON_THROW_ON_ERROR);
        $this->assertSame('cashbook_transaction_from_posted_cash_supplier_payment_v1', $snapshot['contract']);
        $this->assertSame($context['payment_execution_id'], $snapshot['payment_execution']['id']);
        $this->assertSame($context['cash_instrument_id'], $snapshot['cashier_payment_instrument']['id']);
        $this->assertSame('CASH', $snapshot['cashier_payment_instrument']['type']);

        $beforeReplay = $this->cashbookSnapshot($cashbook['id']);
        $replay = $this->postingService->post($context['draft_journal_entry_id'], $this->actor->id);

        $this->assertSame($posted->id, $replay->id);
        $this->assertSame(1, DB::table('cashbook_transactions')->count());
        $this->assertSame($beforeReplay, $this->cashbookSnapshot($cashbook['id']));
    }

    private function makeCashSupplierPaymentDraftContext(): array
    {
        $timestamp = now();
        $vendorId = $this->makeVendor($this->property);
        $apAccountId = $this->makeAccount('AP-' . $this->sequence++, 'AP Control', 'Liability', 'CurrentLiability', 'Credit');
        $cashAccountId = $this->makeAccount('CASH-' . $this->sequence++, 'Cash on Hand', 'Asset', 'CurrentAsset', 'Debit');
        $sourceCandidateId = (string) Str::ulid();
        $sourceJournalEntryId = (string) Str::ulid();
        $supplierInvoiceId = (string) Str::ulid();
        $proposalId = (string) Str::ulid();
        $proposalItemId = (string) Str::ulid();
        $cashierSessionId = (string) Str::ulid();
        $cashInstrumentId = (string) Str::ulid();
        $paymentExecutionId = (string) Str::ulid();
        $paymentCandidateId = (string) Str::ulid();
        $draftJournalEntryId = (string) Str::ulid();

        DB::table('journal_candidates')->insert([
            'id' => $sourceCandidateId,
            'property_id' => $this->property->id,
            'source_type' => 'SupplierInvoice',
            'source_id' => $supplierInvoiceId,
            'posting_event' => 'SupplierInvoiceGrniClearingApLiability',
            'status' => 'APPROVED',
            'candidate_date' => '2026-07-01',
            'description' => 'Source AP liability candidate',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['contract' => 'test_source_ap_liability']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $sourceJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => '2026-07-01',
            'reference' => 'AP-SOURCE-' . $this->sequence++,
            'description' => 'Posted AP liability source',
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

        DB::table('payment_proposals')->insert([
            'id' => $proposalId,
            'property_id' => $this->property->id,
            'vendor_id' => $vendorId,
            'proposal_number' => 'PAY-' . $this->sequence++,
            'currency_code' => 'IDR',
            'status' => 'APPROVED',
            'source_fingerprint' => hash('sha256', $sourceJournalEntryId),
            'total_amount' => 125,
            'submitted_by' => $this->actor->id,
            'submitted_at' => $timestamp,
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('payment_proposal_items')->insert([
            'id' => $proposalItemId,
            'payment_proposal_id' => $proposalId,
            'property_id' => $this->property->id,
            'source_journal_entry_id' => $sourceJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'vendor_id' => $vendorId,
            'currency_code' => 'IDR',
            'source_amount' => 125,
            'is_active' => true,
            'source_snapshot' => json_encode(['source_journal_entry_id' => $sourceJournalEntryId]),
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_sessions')->insert([
            'id' => $cashierSessionId,
            'property_id' => $this->property->id,
            'cashier_user_id' => $this->actor->id,
            'status' => 'OPEN',
            'opened_at' => $timestamp,
            'opened_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('cashier_payment_instruments')->insert([
            'id' => $cashInstrumentId,
            'property_id' => $this->property->id,
            'name' => 'Cash Instrument ' . $this->sequence++,
            'type' => 'CASH',
            'operational_gl_account_id' => $cashAccountId,
            'is_active' => true,
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
            'source_journal_entry_id' => $sourceJournalEntryId,
            'source_journal_candidate_id' => $sourceCandidateId,
            'supplier_invoice_id' => $supplierInvoiceId,
            'cashier_session_id' => $cashierSessionId,
            'cashier_payment_instrument_id' => $cashInstrumentId,
            'operational_gl_account_id' => $cashAccountId,
            'currency_code' => 'IDR',
            'source_amount' => 125,
            'executed_by' => $this->actor->id,
            'executed_at' => $timestamp,
            'source_snapshot' => json_encode(['payment_proposal_item_id' => $proposalItemId]),
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
            'description' => 'Supplier payment cash disbursement candidate',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'approved_by' => $this->actor->id,
            'approved_at' => $timestamp,
            'metadata' => json_encode(['contract' => 'supplier_payment_cash_disbursement_candidate_v1']),
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entries')->insert([
            'id' => $draftJournalEntryId,
            'property_id' => $this->property->id,
            'transaction_date' => '2026-07-01',
            'posting_date' => null,
            'reference' => 'PAY-POST-' . $this->sequence++,
            'description' => 'Supplier payment cash disbursement draft',
            'status' => 'Draft',
            'source_module' => 'GeneralCashier',
            'source_type' => 'PaymentExecution',
            'source_id' => $paymentExecutionId,
            'journal_candidate_id' => $paymentCandidateId,
            'posting_event' => 'SupplierPaymentCashDisbursement',
            'draft_finalization_authorized_by' => $this->actor->id,
            'draft_finalization_authorized_at' => $timestamp,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('gl_journal_entry_lines')->insert([
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $draftJournalEntryId,
                'account_id' => $apAccountId,
                'debit_amount' => 125,
                'credit_amount' => 0,
                'memo' => 'Debit AP liability control',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
            [
                'id' => (string) Str::ulid(),
                'property_id' => $this->property->id,
                'journal_entry_id' => $draftJournalEntryId,
                'account_id' => $cashAccountId,
                'debit_amount' => 0,
                'credit_amount' => 125,
                'memo' => 'Credit General Cashier cash account',
                'created_by' => $this->actor->id,
                'updated_by' => $this->actor->id,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ],
        ]);

        return [
            'cash_account_id' => $cashAccountId,
            'cash_instrument_id' => $cashInstrumentId,
            'payment_execution_id' => $paymentExecutionId,
            'draft_journal_entry_id' => $draftJournalEntryId,
        ];
    }

    private function openPostingControls(Property $property, string $date): void
    {
        $timestamp = now();
        $year = (int) date('Y', strtotime($date));
        $month = (int) date('m', strtotime($date));

        DB::table('gl_financial_periods')->updateOrInsert(
            [
                'property_id' => $property->id,
                'period_year' => $year,
                'period_month' => $month,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => 'Open',
                'opened_at' => $timestamp,
                'closed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );

        DB::table('property_business_dates')->updateOrInsert(
            [
                'property_id' => $property->id,
                'business_date' => $date,
            ],
            [
                'id' => (string) Str::ulid(),
                'status' => 'Open',
                'is_open' => true,
                'opened_at' => $timestamp,
                'closed_at' => null,
                'created_at' => $timestamp,
                'updated_at' => $timestamp,
            ]
        );
    }

    private function makeAccount(
        string $code,
        string $name,
        string $accountType,
        string $accountCategory,
        string $normalBalance
    ): string {
        $accountId = (string) Str::ulid();
        $timestamp = now();

        DB::table('gl_accounts')->insert([
            'id' => $accountId,
            'property_id' => $this->property->id,
            'code' => $code,
            'name' => $name,
            'normal_balance' => $normalBalance,
            'account_type' => $accountType,
            'account_category' => $accountCategory,
            'is_active' => true,
            'is_cash_equivalent' => $accountType === 'Asset',
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $accountId;
    }

    private function makeVendor(Property $property): string
    {
        $categoryId = (string) Str::ulid();
        $vendorId = (string) Str::ulid();
        $timestamp = now();

        DB::table('vendor_categories')->insert([
            'id' => $categoryId,
            'property_id' => $property->id,
            'category_code' => 'VC-' . $this->sequence++,
            'name' => 'Cashbook Vendor Category',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('vendors')->insert([
            'id' => $vendorId,
            'property_id' => $property->id,
            'vendor_category_id' => $categoryId,
            'vendor_code' => 'VEND-' . $this->sequence++,
            'name' => 'Cashbook Vendor',
            'default_currency_code' => 'IDR',
            'is_active' => true,
            'is_approved' => true,
            'performance_score' => 0,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return $vendorId;
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
            'name' => 'Cashbook Company ' . $suffix,
            'slug' => 'cashbook-company-' . $suffix,
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        DB::table('properties')->insert([
            'id' => $propertyId,
            'company_id' => $companyId,
            'name' => 'Cashbook Property ' . $suffix,
            'slug' => 'cashbook-property-' . $suffix,
            'code' => 'CB' . $suffix,
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
            'name' => 'Cashbook User ' . $suffix,
            'email' => 'cashbook-user-' . $suffix . '@example.test',
            'password' => 'not-used',
            'is_active' => true,
            'created_at' => $timestamp,
            'updated_at' => $timestamp,
        ]);

        return User::query()->findOrFail($userId);
    }

    private function cashbookSnapshot(string $cashbookId): array
    {
        return (array) DB::table('cashbook_transactions')
            ->where('id', $cashbookId)
            ->first([
                'property_id',
                'operational_gl_account_id',
                'currency_code',
                'amount',
                'direction',
                'posted_business_date',
                'journal_entry_id',
                'payment_execution_id',
                'source_module',
                'source_type',
                'source_id',
                'source_event',
                'source_identity_hash',
                'source_snapshot',
                'projected_by',
                'projected_at',
                'created_by',
                'created_at',
            ]);
    }
}
