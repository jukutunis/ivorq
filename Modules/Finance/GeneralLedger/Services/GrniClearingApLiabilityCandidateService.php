<?php

namespace Modules\Finance\GeneralLedger\Services;

use Carbon\Carbon;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\EntryTypeEnum;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Enums\OperationalIdentityEnum;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityMappingNotFoundException;
use Modules\Finance\GeneralLedger\Exceptions\OperationalIdentityValidationException;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\Payables\Models\SupplierInvoice;
use Modules\Finance\Payables\Services\GrniClearingAllocationEligibilityService;
use Modules\Foundation\User\Models\User;
use Throwable;

class GrniClearingApLiabilityCandidateService
{
    public const PERMISSION = 'finance.payables.grni-clearing.candidate.create';
    public const SOURCE_TYPE = 'SupplierInvoice';
    public const POSTING_EVENT = 'SupplierInvoiceGrniClearingApLiability';

    public function __construct(
        private readonly GrniClearingAllocationEligibilityService $eligibilityService,
        private readonly OperationalIdentityMappingService $mappingService,
        private readonly OperationalIdentityValidationService $validationService,
    ) {}

    public function createForSupplierInvoice(string $supplierInvoiceId): JournalCandidate
    {
        return DB::transaction(function () use ($supplierInvoiceId) {
            $actor = $this->resolveActiveActor();

            $invoice = SupplierInvoice::withoutGlobalScope('property')
                ->with(['lines', 'threeWayMatch.lines'])
                ->whereKey($supplierInvoiceId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $invoice->property_id);
            $eligibility = $this->eligibilityService->evaluate($invoice->id);

            if ($eligibility['decision'] !== GrniClearingAllocationEligibilityService::DECISION_ELIGIBLE) {
                throw new DomainException('Supplier invoice is not eligible for GRNI clearing candidate creation: ' . implode(', ', $eligibility['blockers']));
            }

            $source = $this->resolveSourceEvidence($invoice, $eligibility);
            $candidateDate = Carbon::parse($invoice->approved_at)->toDateString();
            $accountEvidence = $this->resolveAccountEvidence($invoice->property_id, $candidateDate, $source);
            $amount = $this->assertSupportedAmount($invoice, $source, $accountEvidence);
            $metadata = $this->candidateMetadata($invoice, $eligibility, $source, $accountEvidence, $amount);
            $identity = $this->candidateIdentity($invoice->property_id, $invoice->id);
            $sourceIdentity = $this->sourceGrniIdentity($source);

            $existing = JournalCandidate::where($identity)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingCandidateMatches($existing, $metadata, $amount, $sourceIdentity);

                return $existing->fresh(['lines']);
            }

            $this->assertNoSourceGrniReuse($invoice->property_id, $sourceIdentity);

            $candidate = new JournalCandidate($identity + $sourceIdentity + [
                'status' => JournalCandidateStatusEnum::PENDING_REVIEW->value,
                'candidate_date' => $candidateDate,
                'description' => 'GRNI Clearing and AP Liability Candidate for Supplier Invoice ' . $invoice->invoice_number,
                'metadata' => $metadata,
            ]);
            $candidate->created_by = $actor->id;
            $candidate->updated_by = $actor->id;
            $candidate->save();

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::GRNI_RECEIPT->value,
                'entry_type' => EntryTypeEnum::DEBIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['grni_mapping']->cost_center_id,
                'notes' => 'Debit posted GRNI liability source for approved supplier invoice.',
            ]);

            $candidate->lines()->create([
                'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                'entry_type' => EntryTypeEnum::CREDIT->value,
                'amount' => $amount,
                'cost_center_id' => $accountEvidence['ap_mapping']->cost_center_id,
                'notes' => 'Credit AP liability control for approved supplier invoice.',
            ]);

            return $candidate->fresh(['lines']);
        });
    }

    private function resolveActiveActor(): User
    {
        $authUser = Auth::user();

        if (!$authUser) {
            throw new AuthorizationException('GRNI clearing candidate creation requires an authenticated actor.');
        }

        $actor = User::where('id', $authUser->id)
            ->where('is_active', true)
            ->first();

        if (!$actor) {
            throw new AuthorizationException('GRNI clearing candidate creation actor is inactive or unresolved.');
        }

        try {
            $authorized = $actor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('GRNI clearing candidate creation permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('GRNI clearing candidate creation permission is required.');
        }

        return $actor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('GRNI clearing candidate creation requires active property access.');
        }
    }

    private function resolveSourceEvidence(SupplierInvoice $invoice, array $eligibility): array
    {
        if ($invoice->approved_at === null) {
            throw new DomainException('Approved supplier invoice is missing approval timestamp evidence.');
        }

        $invoiceLine = $invoice->lines->first();
        $sourceEvidence = $eligibility['source_evidence'];
        $lineEvidence = $eligibility['lines'][0] ?? [];

        $sourceGrniCandidate = DB::table('journal_candidates')
            ->where('id', $sourceEvidence['grni_candidate_id'])
            ->where('property_id', $invoice->property_id)
            ->where('source_type', 'InventoryReceipt')
            ->where('posting_event', 'InventoryReceiptAccrual')
            ->lockForUpdate()
            ->first();

        if (!$sourceGrniCandidate) {
            throw new DomainException('Source GRNI candidate evidence is missing.');
        }

        $postedJournal = DB::table('gl_journal_entries')
            ->where('id', $sourceEvidence['posted_journal_entry_id'])
            ->where('journal_candidate_id', $sourceGrniCandidate->id)
            ->where('property_id', $invoice->property_id)
            ->where('status', 'Posted')
            ->whereNull('deleted_at')
            ->lockForUpdate()
            ->first();

        if (!$postedJournal || $postedJournal->posted_by === null || $postedJournal->posted_at === null) {
            throw new DomainException('Posted GRNI JournalEntry evidence is missing.');
        }

        $postedLines = DB::table('gl_journal_entry_lines')
            ->where('journal_entry_id', $postedJournal->id)
            ->orderBy('id')
            ->get();

        if ($postedLines->isEmpty()) {
            throw new DomainException('Posted GRNI JournalEntry line evidence is missing.');
        }

        $sourceGrniLines = DB::table('journal_candidate_lines')
            ->where('journal_candidate_id', $sourceGrniCandidate->id)
            ->orderBy('id')
            ->get();

        if ($sourceGrniLines->isEmpty()) {
            throw new DomainException('Source GRNI candidate line evidence is missing.');
        }

        return [
            'invoice_line' => $invoiceLine,
            'source_grni_candidate' => $sourceGrniCandidate,
            'posted_journal' => $postedJournal,
            'posted_lines' => $postedLines,
            'source_grni_lines' => $sourceGrniLines,
            'purchase_order_id' => $sourceEvidence['purchase_order_id'],
            'receiving_document_id' => $sourceEvidence['receiving_document_id'],
            'purchase_order_line_id' => $lineEvidence['purchase_order_line_id'] ?? null,
            'receiving_line_id' => $lineEvidence['receiving_line_id'] ?? null,
            'inventory_receipt_line_id' => $lineEvidence['inventory_receipt_line_id'] ?? null,
        ];
    }

    private function resolveAccountEvidence(string $propertyId, string $candidateDate, array $source): array
    {
        $date = Carbon::parse($candidateDate);

        try {
            $grniMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::GRNI_RECEIPT, $date);
            $this->validationService->validate(OperationalIdentityEnum::GRNI_RECEIPT, $grniMapping->account);
            $this->assertActivePropertyAccount($grniMapping->account, $propertyId, 'GRNI liability');

            $apMapping = $this->mappingService->resolve($propertyId, OperationalIdentityEnum::AP_CONTROL, $date);
            $this->validationService->validate(OperationalIdentityEnum::AP_CONTROL, $apMapping->account);
            $this->assertActivePropertyAccount($apMapping->account, $propertyId, 'AP liability control');
        } catch (OperationalIdentityMappingNotFoundException | OperationalIdentityValidationException | DomainException) {
            throw new DomainException('GRNI clearing AP liability account evidence is unavailable for candidate creation.');
        }

        $postedCreditLines = $source['posted_lines']
            ->filter(fn (object $line): bool => $this->amountToCents($line->credit_amount) > 0)
            ->values();

        if ($postedCreditLines->count() !== 1) {
            throw new DomainException('Posted GRNI JournalEntry must contain exactly one credit liability line.');
        }

        $postedGrniLine = $postedCreditLines->first();

        if ($postedGrniLine->account_id !== $grniMapping->account_id) {
            throw new DomainException('Posted GRNI liability account conflicts with active GRNI_RECEIPT mapping.');
        }

        $sourceGrniCreditLines = $source['source_grni_lines']
            ->filter(function (object $line): bool {
                return $line->operational_identity === OperationalIdentityEnum::GRNI_RECEIPT->value
                    && $line->entry_type === EntryTypeEnum::CREDIT->value;
            })
            ->values();

        if ($sourceGrniCreditLines->count() !== 1) {
            throw new DomainException('Source GRNI candidate must contain exactly one GRNI_RECEIPT credit line.');
        }

        return [
            'grni_mapping' => $grniMapping,
            'ap_mapping' => $apMapping,
            'posted_grni_line' => $postedGrniLine,
            'source_grni_line' => $sourceGrniCreditLines->first(),
        ];
    }

    private function assertActivePropertyAccount(object $account, string $propertyId, string $label): void
    {
        if ($account->property_id !== $propertyId || !$account->is_active || $account->deleted_at !== null) {
            throw new DomainException($label . ' account is not active for the supplier invoice property.');
        }
    }

    private function assertSupportedAmount(SupplierInvoice $invoice, array $source, array $accountEvidence): string
    {
        $invoiceLineAmount = $this->amountToCents($source['invoice_line']->line_total);
        $postedGrniAmount = $this->amountToCents($accountEvidence['posted_grni_line']->credit_amount);
        $sourceGrniAmount = $this->amountToCents($accountEvidence['source_grni_line']->amount);
        $invoiceTotal = $this->amountToCents($invoice->grand_total);

        if ($invoiceLineAmount <= 0 || $invoiceLineAmount !== $invoiceTotal) {
            throw new DomainException('Supplier invoice amount is not supported for one-to-one GRNI clearing candidate creation.');
        }

        if ($invoiceLineAmount !== $postedGrniAmount || $invoiceLineAmount !== $sourceGrniAmount) {
            throw new DomainException('Supplier invoice, source GRNI candidate, and posted GRNI liability amounts do not match exactly.');
        }

        return number_format($invoiceLineAmount / 100, 2, '.', '');
    }

    private function candidateMetadata(SupplierInvoice $invoice, array $eligibility, array $source, array $accountEvidence, string $amount): array
    {
        return [
            'contract' => 'grni_clearing_ap_liability_candidate_v1',
            'currency_code' => $eligibility['source_currency'],
            'amount' => $amount,
            'supplier_invoice' => [
                'id' => $invoice->id,
                'invoice_number' => $invoice->invoice_number,
                'vendor_id' => $invoice->vendor_id,
                'property_id' => $invoice->property_id,
                'approved_by' => $invoice->approved_by,
                'approved_at' => $invoice->approved_at?->toISOString(),
            ],
            'supplier_invoice_line' => [
                'id' => $source['invoice_line']->id,
                'quantity' => (string) $source['invoice_line']->quantity,
                'unit_price' => (string) $source['invoice_line']->unit_price,
                'line_total' => $amount,
            ],
            'purchase_order' => [
                'id' => $source['purchase_order_id'],
                'line_id' => $source['purchase_order_line_id'],
            ],
            'receiving' => [
                'document_id' => $source['receiving_document_id'],
                'line_id' => $source['receiving_line_id'],
                'inventory_receipt_line_id' => $source['inventory_receipt_line_id'],
            ],
            'source_grni' => [
                'candidate_id' => $source['source_grni_candidate']->id,
                'journal_entry_id' => $source['posted_journal']->id,
                'journal_entry_line_id' => $accountEvidence['posted_grni_line']->id,
                'account_id' => $accountEvidence['posted_grni_line']->account_id,
                'amount' => $amount,
            ],
            'accounts' => [
                'grni_liability' => [
                    'operational_identity' => OperationalIdentityEnum::GRNI_RECEIPT->value,
                    'mapping_id' => $accountEvidence['grni_mapping']->id,
                    'account_id' => $accountEvidence['grni_mapping']->account_id,
                ],
                'ap_liability_control' => [
                    'operational_identity' => OperationalIdentityEnum::AP_CONTROL->value,
                    'mapping_id' => $accountEvidence['ap_mapping']->id,
                    'account_id' => $accountEvidence['ap_mapping']->account_id,
                ],
            ],
        ];
    }

    private function candidateIdentity(string $propertyId, string $invoiceId): array
    {
        return [
            'property_id' => $propertyId,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => $invoiceId,
            'posting_event' => self::POSTING_EVENT,
        ];
    }

    private function sourceGrniIdentity(array $source): array
    {
        return [
            'source_grni_candidate_id' => $source['source_grni_candidate']->id,
            'source_grni_journal_entry_id' => $source['posted_journal']->id,
        ];
    }

    private function assertNoSourceGrniReuse(string $propertyId, array $sourceIdentity): void
    {
        $collision = JournalCandidate::where('property_id', $propertyId)
            ->where('posting_event', self::POSTING_EVENT)
            ->where(function ($query) use ($sourceIdentity) {
                $query->where('source_grni_candidate_id', $sourceIdentity['source_grni_candidate_id'])
                    ->orWhere('source_grni_journal_entry_id', $sourceIdentity['source_grni_journal_entry_id']);
            })
            ->lockForUpdate()
            ->first();

        if ($collision) {
            throw new DomainException('Posted GRNI source already has a GRNI clearing AP liability candidate.');
        }
    }

    private function assertExistingCandidateMatches(JournalCandidate $candidate, array $metadata, string $amount, array $sourceIdentity): void
    {
        if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
            throw new DomainException('Existing GRNI clearing AP liability candidate is no longer PENDING_REVIEW.');
        }

        if ($candidate->source_grni_candidate_id !== $sourceIdentity['source_grni_candidate_id']
            || $candidate->source_grni_journal_entry_id !== $sourceIdentity['source_grni_journal_entry_id']
            || $candidate->metadata !== $metadata) {
            throw new DomainException('Existing GRNI clearing AP liability candidate conflicts with current source evidence.');
        }

        $lines = $candidate->lines()
            ->orderBy('created_at')
            ->orderBy('id')
            ->get();

        if ($lines->count() !== 2) {
            throw new DomainException('Existing GRNI clearing AP liability candidate line count conflicts with current source evidence.');
        }

        $expected = [
            [OperationalIdentityEnum::GRNI_RECEIPT->value, EntryTypeEnum::DEBIT->value],
            [OperationalIdentityEnum::AP_CONTROL->value, EntryTypeEnum::CREDIT->value],
        ];

        foreach ($lines->values() as $index => $line) {
            [$identity, $entryType] = $expected[$index];

            if ($line->operational_identity->value !== $identity
                || $line->entry_type->value !== $entryType
                || number_format((float) $line->amount, 2, '.', '') !== $amount) {
                throw new DomainException('Existing GRNI clearing AP liability candidate lines conflict with current source evidence.');
            }
        }
    }

    private function amountToCents(mixed $amount): int
    {
        return (int) round(((float) $amount) * 100);
    }
}
