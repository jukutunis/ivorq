<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Enums\JournalCandidateStatusEnum;
use Modules\Finance\GeneralLedger\Models\JournalCandidate;
use Modules\Finance\GeneralLedger\Models\JournalEntry;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Models\PaymentExecution;
use Modules\Operations\GeneralCashier\Models\PaymentExecutionVoidEvidence;
use Throwable;

class PaymentExecutionVoidService
{
    public const PERMISSION = 'finance.general-cashier.payment.void';
    public const CONTRACT = 'payment_execution_pre_post_void_v1';
    public const PAYMENT_POSTING_EVENT = 'SupplierPaymentCashDisbursement';

    public function void(string $paymentExecutionId, string $reason, ?User $actor): PaymentExecutionVoidEvidence
    {
        return DB::transaction(function () use ($paymentExecutionId, $reason, $actor): PaymentExecutionVoidEvidence {
            $actor = $this->resolveAuthorizedActor($actor);
            $reason = trim($reason);

            if ($reason === '') {
                throw new DomainException('Payment Execution VOID requires a reason.');
            }

            $execution = PaymentExecution::whereKey($paymentExecutionId)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertActorCanAccessProperty($actor, $execution->property_id);

            $paymentCandidates = $this->lockPaymentCandidates($execution);
            $this->assertNoApprovedCandidateDraftOrPosting($execution, $paymentCandidates);

            $identityHash = $this->sourceIdentityHash($execution, $reason, $actor->id, $paymentCandidates);
            $snapshot = $this->sourceSnapshot($execution, $reason, $actor->id, $paymentCandidates);

            $existing = PaymentExecutionVoidEvidence::where('payment_execution_id', $execution->id)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                $this->assertExistingVoidMatches($existing, $reason, $actor->id, $identityHash);

                return $existing->fresh();
            }

            $void = new PaymentExecutionVoidEvidence([
                'payment_execution_id' => $execution->id,
                'property_id' => $execution->property_id,
                'vendor_id' => $execution->vendor_id,
                'payment_proposal_id' => $execution->payment_proposal_id,
                'payment_proposal_item_id' => $execution->payment_proposal_item_id,
                'source_journal_entry_id' => $execution->source_journal_entry_id,
                'source_journal_candidate_id' => $execution->source_journal_candidate_id,
                'supplier_invoice_id' => $execution->supplier_invoice_id,
                'operational_gl_account_id' => $execution->operational_gl_account_id,
                'currency_code' => $execution->currency_code,
                'source_amount' => $this->amountString($execution->source_amount),
                'void_reason' => $reason,
                'voided_by' => $actor->id,
                'voided_at' => now(),
                'source_identity_hash' => $identityHash,
                'source_snapshot' => $snapshot,
            ]);
            $void->created_by = $actor->id;
            $void->updated_by = $actor->id;
            $void->save();

            return $void->fresh();
        });
    }

    private function resolveAuthorizedActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('Payment Execution VOID requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('Payment Execution VOID requires an active actor.');
        }

        try {
            $authorized = $freshActor->can(self::PERMISSION);
        } catch (Throwable) {
            throw new AuthorizationException('Payment Execution VOID permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('Payment Execution VOID permission is required.');
        }

        return $freshActor;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('Payment Execution VOID requires active property access.');
        }
    }

    /**
     * @return Collection<int, JournalCandidate>
     */
    private function lockPaymentCandidates(PaymentExecution $execution): Collection
    {
        return JournalCandidate::where('property_id', $execution->property_id)
            ->where('source_type', 'PaymentExecution')
            ->where('source_id', $execution->id)
            ->where('posting_event', self::PAYMENT_POSTING_EVENT)
            ->orderBy('created_at')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param Collection<int, JournalCandidate> $paymentCandidates
     */
    private function assertNoApprovedCandidateDraftOrPosting(PaymentExecution $execution, Collection $paymentCandidates): void
    {
        foreach ($paymentCandidates as $candidate) {
            if ($candidate->status !== JournalCandidateStatusEnum::PENDING_REVIEW) {
                throw new DomainException('Payment Execution has crossed supplier payment candidate review.');
            }
        }

        $journalExists = JournalEntry::where('property_id', $execution->property_id)
            ->where('source_type', 'PaymentExecution')
            ->where('source_id', $execution->id)
            ->where('posting_event', self::PAYMENT_POSTING_EVENT)
            ->lockForUpdate()
            ->first();

        if ($journalExists) {
            throw new DomainException('Payment Execution has crossed supplier payment draft or posting.');
        }
    }

    private function assertExistingVoidMatches(
        PaymentExecutionVoidEvidence $existing,
        string $reason,
        string $actorId,
        string $identityHash
    ): void {
        if (
            $existing->void_reason === $reason &&
            $existing->voided_by === $actorId &&
            $existing->voided_at !== null &&
            $existing->source_identity_hash === $identityHash
        ) {
            return;
        }

        throw new DomainException('Conflicting Payment Execution VOID evidence already exists.');
    }

    /**
     * @param Collection<int, JournalCandidate> $paymentCandidates
     */
    private function sourceIdentityHash(
        PaymentExecution $execution,
        string $reason,
        string $actorId,
        Collection $paymentCandidates
    ): string {
        return hash('sha256', implode('|', [
            self::CONTRACT,
            $execution->id,
            $execution->property_id,
            $execution->vendor_id,
            $execution->payment_proposal_id,
            $execution->payment_proposal_item_id,
            $execution->source_journal_entry_id,
            $execution->source_journal_candidate_id,
            $execution->supplier_invoice_id,
            $execution->operational_gl_account_id,
            $execution->currency_code,
            $this->amountString($execution->source_amount),
            $reason,
            $actorId,
            $paymentCandidates->map(fn (JournalCandidate $candidate): string => $candidate->id . ':' . $candidate->status->value)->implode(','),
        ]));
    }

    /**
     * @param Collection<int, JournalCandidate> $paymentCandidates
     */
    private function sourceSnapshot(
        PaymentExecution $execution,
        string $reason,
        string $actorId,
        Collection $paymentCandidates
    ): array {
        return [
            'contract' => self::CONTRACT,
            'payment_execution_id' => $execution->id,
            'property_id' => $execution->property_id,
            'vendor_id' => $execution->vendor_id,
            'payment_proposal_id' => $execution->payment_proposal_id,
            'payment_proposal_item_id' => $execution->payment_proposal_item_id,
            'source_journal_entry_id' => $execution->source_journal_entry_id,
            'source_journal_candidate_id' => $execution->source_journal_candidate_id,
            'supplier_invoice_id' => $execution->supplier_invoice_id,
            'operational_gl_account_id' => $execution->operational_gl_account_id,
            'currency_code' => $execution->currency_code,
            'source_amount' => $this->amountString($execution->source_amount),
            'void_reason' => $reason,
            'voided_by' => $actorId,
            'payment_journal_candidates' => $paymentCandidates->map(
                fn (JournalCandidate $candidate): array => [
                    'id' => $candidate->id,
                    'status' => $candidate->status->value,
                ]
            )->values()->all(),
        ];
    }

    private function amountString(mixed $amount): string
    {
        return number_format(((float) $amount), 2, '.', '');
    }
}
