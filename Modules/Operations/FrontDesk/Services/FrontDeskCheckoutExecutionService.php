<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Modules\Foundation\Audit\Services\AuditService;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationClaimResult;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Foundation\Authorization\ValueObjects\CheckoutSensitiveConfirmationPreflightResult;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\ValueObjects\FrontDeskCheckoutExecutionResult;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use RuntimeException;
use Symfony\Component\HttpKernel\Exception\ConflictHttpException;

class FrontDeskCheckoutExecutionService
{
    public const ERROR_IDEMPOTENCY_CONFLICT = 'P9_CHECKOUT_IDEMPOTENCY_CONFLICT';
    public const ERROR_ALREADY_COMPLETED = 'P9_CHECKOUT_ALREADY_COMPLETED';
    public const ERROR_REPLAY_SOURCE_INTEGRITY = 'P9_CHECKOUT_REPLAY_SOURCE_INTEGRITY_FAILURE';
    public const ERROR_FINAL_REVIEW_NOT_READY = 'P9_CHECKOUT_FINAL_REVIEW_NOT_READY';
    public const ERROR_STAY_NOT_IN_HOUSE = 'P9_CHECKOUT_STAY_NOT_IN_HOUSE';
    public const ERROR_NIGHT_AUDIT_ACTIVE = 'P9_CHECKOUT_NIGHT_AUDIT_ACTIVE';
    public const ERROR_FINANCIAL_NOT_READY = 'P9_CHECKOUT_TERMINAL_FINANCIAL_NOT_READY';
    public const ERROR_CASHIER_NOT_CLEAR = 'P9_CHECKOUT_CASHIER_OBLIGATION_NOT_CLEAR';
    public const ERROR_CONFIRMATION_CHANGED = 'P9_CHECKOUT_CONFIRMATION_CHANGED_DURING_EXECUTION';
    public const ERROR_POSTGRESQL_REQUIRED = 'P9_CHECKOUT_POSTGRESQL_REQUIRED';

    private const MAX_ATTEMPTS = 3;

    public function __construct(
        private readonly FrontDeskCheckoutExecuteAuthorizationService $authorization,
        private readonly CheckoutSensitiveConfirmationService $confirmation,
        private readonly FrontDeskBusinessDateDependencyService $businessDateDependency,
        private readonly PropertyBusinessDateOperationalLockService $businessDateLock,
        private readonly NightAuditCheckoutConcurrencyGuardService $nightAudit,
        private readonly GuestLedgerCheckoutTerminalFinancialAttestationService $financialAttestation,
        private readonly GeneralCashierCheckoutTerminalObligationAttestationService $cashierAttestation,
        private readonly AuditService $auditService,
    ) {}

    public function execute(User $actor, string $frontDeskStayId, string $idempotencyKey): FrontDeskCheckoutExecutionResult
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new DomainException(self::ERROR_POSTGRESQL_REQUIRED);
        }

        $context = $this->authorization->resolveAuthorizedContext($actor, $frontDeskStayId);
        $idempotencyKey = $this->confirmation->normalizeIdempotencyKey($idempotencyKey);

        if ($replay = $this->committedReplay($context['property']->id, $context['stay']->id, $context['stay']->reservation_id, $idempotencyKey)) {
            return $replay;
        }

        $this->assertNoCompletedCheckoutForStay($context['property']->id, $context['stay']->id);

        $preflight = $this->confirmation->validateCurrentSessionConfirmationFor($context['actor'], $context['stay']->id, $idempotencyKey);
        $businessDateEvidence = $this->businessDateDependency->project($context['actor']);
        if (($businessDateEvidence['status'] ?? null) !== FrontDeskBusinessDateDependencyService::BUSINESS_DATE_OPEN) {
            throw new DomainException(FrontDeskBusinessDateDependencyService::BUSINESS_DATE_EVIDENCE_UNAVAILABLE);
        }

        for ($attempt = 1; $attempt <= self::MAX_ATTEMPTS; $attempt++) {
            try {
                $result = DB::transaction(function () use ($context, $idempotencyKey, $preflight, $businessDateEvidence) {
                    return $this->executeAttempt($context, $idempotencyKey, $preflight, $businessDateEvidence);
                }, 1);

                $this->cleanupConfirmationSessionAfterCommit($result, $context['property']->id, $context['stay']->id);

                return $result;
            } catch (QueryException $exception) {
                if ($attempt < self::MAX_ATTEMPTS && $this->isRetryableSqlState($exception)) {
                    $context = $this->authorization->resolveAuthorizedContext($actor, $frontDeskStayId);
                    if ($replay = $this->committedReplay($context['property']->id, $context['stay']->id, $context['stay']->reservation_id, $idempotencyKey)) {
                        return $replay;
                    }
                    $preflight = $this->confirmation->validateCurrentSessionConfirmationFor($context['actor'], $context['stay']->id, $idempotencyKey);
                    $businessDateEvidence = $this->businessDateDependency->project($context['actor']);
                    continue;
                }

                $this->mapQueryException($exception);
            }
        }

        throw new RuntimeException('P9_CHECKOUT_RETRY_EXHAUSTED');
    }

    /**
     * @param array{actor: User, company: mixed, property: mixed, stay: FrontDeskStay} $context
     * @param array<string, mixed> $businessDateEvidence
     */
    private function executeAttempt(
        array $context,
        string $idempotencyKey,
        CheckoutSensitiveConfirmationPreflightResult $preflight,
        array $businessDateEvidence,
    ): FrontDeskCheckoutExecutionResult {
        $actor = $context['actor'];
        $company = $context['company'];
        $property = $context['property'];
        $requestedStay = $context['stay'];

        $operationalContext = $this->businessDateLock->acquire($company->id, $property->id, [
            'property_id' => $property->id,
            'property_business_date_id' => $businessDateEvidence['property_business_date_id'],
            'business_date' => $businessDateEvidence['business_date'],
            'property_timezone' => $businessDateEvidence['property_timezone'],
            'opened_by' => $businessDateEvidence['opened_by'],
            'opened_at' => Carbon::parse($businessDateEvidence['opened_at'])->utc()->toISOString(),
        ]);

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($requestedStay->id)
            ->where('property_id', $property->id)
            ->lockForUpdate()
            ->first();

        if (! $stay || $stay->reservation_id !== $requestedStay->reservation_id || $stay->guest_id !== $requestedStay->guest_id) {
            throw new DomainException(self::ERROR_REPLAY_SOURCE_INTEGRITY);
        }

        $existing = FrontDeskCheckoutExecution::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->where('idempotency_key', $idempotencyKey)
            ->lockForUpdate()
            ->first();

        if ($existing) {
            if ($existing->front_desk_stay_id !== $stay->id || $existing->reservation_id !== $stay->reservation_id) {
                throw new ConflictHttpException(self::ERROR_IDEMPOTENCY_CONFLICT);
            }

            return $this->resultFromExecution($existing, true);
        }

        $this->assertNoCompletedCheckoutForStay($property->id, $stay->id, lock: true);

        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) {
            throw new DomainException(self::ERROR_STAY_NOT_IN_HOUSE);
        }

        $finalReview = FrontDeskDepartureCheckoutFinalReview::withoutGlobalScopes()
            ->where('property_id', $property->id)
            ->where('front_desk_stay_id', $stay->id)
            ->orderByDesc('occurred_at')
            ->orderByDesc('created_at')
            ->lockForUpdate()
            ->first();

        if (! $finalReview || $finalReview->final_review_status !== FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady) {
            throw new DomainException(self::ERROR_FINAL_REVIEW_NOT_READY);
        }

        $nightAudit = $this->nightAudit->attest($operationalContext);
        if ($nightAudit->status !== NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR || $nightAudit->close_lock_active !== false) {
            throw new DomainException(self::ERROR_NIGHT_AUDIT_ACTIVE);
        }

        $financial = $this->financialAttestation->attest($operationalContext, $stay->id);
        if ($financial->status !== GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady) {
            throw new DomainException(self::ERROR_FINANCIAL_NOT_READY);
        }

        $cashier = $this->cashierAttestation->attest($operationalContext, $financial);
        if ($cashier->status !== GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear) {
            throw new DomainException(self::ERROR_CASHIER_NOT_CLEAR);
        }

        $this->businessDateLock->assertIssuedForCurrentTransaction($operationalContext);
        $this->financialAttestation->assertIssuedForCurrentTransaction($operationalContext, $financial);
        $this->cashierAttestation->assertIssuedForCurrentTransaction($operationalContext, $financial, $cashier);
        $this->authorization->authorize($actor);

        $claim = $this->confirmation->claimCurrentSessionConfirmationFor($actor, $stay->id, $idempotencyKey);
        $this->assertClaimMatchesPreflight($claim, $preflight);

        $occurredAt = $this->postgresWallClockUtc();
        $sourceHash = $this->executionSourceHash(
            $property->id,
            $stay->id,
            $stay->reservation_id,
            $idempotencyKey,
            $finalReview->id,
            $operationalContext->property_business_date_id,
            $operationalContext->business_date,
            $nightAudit->status,
            $nightAudit->source_fingerprint,
            $financial->status->value,
            $financial->source_fingerprint,
            $cashier->status->value,
            $cashier->source_fingerprint,
            $claim,
            $actor->id,
            $occurredAt,
        );

        $execution = new FrontDeskCheckoutExecution();
        $execution->forceFill([
            'property_id' => $property->id,
            'checkout_confirmation_consumption_id' => $claim->consumptionId,
            'checkout_confirmation_fingerprint' => $claim->confirmationFingerprint,
            'checkout_confirmed_at' => $claim->confirmedAt,
            'checkout_confirmation_expires_at' => $claim->expiresAt,
            'checkout_confirmation_consumed_at' => $claim->consumedAt,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'idempotency_key' => $idempotencyKey,
            'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut->value,
            'front_desk_final_review_id' => $finalReview->id,
            'property_business_date_id' => $operationalContext->property_business_date_id,
            'business_date' => $operationalContext->business_date,
            'night_audit_source_status' => $nightAudit->status,
            'night_audit_source_fingerprint' => $nightAudit->source_fingerprint,
            'pms_financial_attestation_status' => $financial->status->value,
            'pms_financial_attestation_fingerprint' => $financial->source_fingerprint,
            'general_cashier_attestation_status' => $cashier->status->value,
            'general_cashier_attestation_fingerprint' => $cashier->source_fingerprint,
            'source_hash' => $sourceHash,
            'occurred_at' => $occurredAt,
            'created_by' => $actor->id,
            'created_at' => $occurredAt,
        ])->save();

        $stay->forceFill([
            'status' => FrontDeskStayStatusEnum::CheckedOut,
            'updated_by' => $actor->id,
        ])->save();

        $handoff = $this->createHandoff($execution, $occurredAt);

        $this->auditService->log(
            'front_desk_checkout_completed',
            $actor,
            [],
            [
                'property_id' => $property->id,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'checkout_execution_id' => $execution->id,
                'idempotency_fingerprint' => hash('sha256', $idempotencyKey),
                'business_date' => $operationalContext->business_date,
                'handoff_id' => $handoff->id,
            ],
            ['front-desk-checkout', $property->id, $stay->id]
        );

        return $this->resultFromExecution($execution, false, $handoff);
    }

    public function committedReplayFor(User $actor, string $frontDeskStayId, string $idempotencyKey): ?FrontDeskCheckoutExecutionResult
    {
        $context = $this->authorization->resolveAuthorizedContext($actor, $frontDeskStayId);
        $idempotencyKey = $this->confirmation->normalizeIdempotencyKey($idempotencyKey);

        return $this->committedReplay(
            $context['property']->id,
            $context['stay']->id,
            $context['stay']->reservation_id,
            $idempotencyKey
        );
    }

    private function committedReplay(string $propertyId, string $stayId, string $reservationId, string $idempotencyKey): ?FrontDeskCheckoutExecutionResult
    {
        $execution = FrontDeskCheckoutExecution::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();

        if (! $execution) {
            return null;
        }

        if ($execution->front_desk_stay_id !== $stayId || $execution->reservation_id !== $reservationId) {
            throw new ConflictHttpException(self::ERROR_IDEMPOTENCY_CONFLICT);
        }

        return $this->resultFromExecution($execution, true);
    }

    private function assertNoCompletedCheckoutForStay(string $propertyId, string $stayId, bool $lock = false): void
    {
        $query = FrontDeskCheckoutExecution::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId);

        if ($lock) {
            $query->lockForUpdate();
        }

        if ($query->exists()) {
            throw new ConflictHttpException(self::ERROR_ALREADY_COMPLETED);
        }
    }

    private function createHandoff(FrontDeskCheckoutExecution $execution, Carbon $occurredAt): FrontDeskCheckoutHousekeepingHandoff
    {
        $handoffKey = 'p9-hk-handoff|' . $execution->property_id . '|' . $execution->id;
        $correlationKey = 'p9-checkout|' . $execution->property_id . '|' . $execution->front_desk_stay_id . '|' . $execution->id;
        $sourceHash = hash('sha256', json_encode([
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date?->format('Y-m-d'),
            'terminal_stay_status' => $execution->terminal_stay_status?->value,
            'execution_source_hash' => $execution->source_hash,
            'occurred_at' => $occurredAt->toDateTimeString(),
        ], JSON_UNESCAPED_SLASHES));

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $handoff->forceFill([
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $handoffKey,
            'correlation_key' => $correlationKey,
            'source_hash' => $sourceHash,
            'delivery_status' => FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending->value,
            'attempts' => 0,
            'available_at' => $occurredAt,
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ])->save();

        return $handoff;
    }

    private function resultFromExecution(FrontDeskCheckoutExecution $execution, bool $replayed, ?FrontDeskCheckoutHousekeepingHandoff $handoff = null): FrontDeskCheckoutExecutionResult
    {
        $handoff ??= FrontDeskCheckoutHousekeepingHandoff::withoutGlobalScopes()
            ->where('checkout_execution_id', $execution->id)
            ->first();

        if (! $handoff) {
            throw new DomainException(self::ERROR_REPLAY_SOURCE_INTEGRITY);
        }

        return new FrontDeskCheckoutExecutionResult(
            propertyId: $execution->property_id,
            frontDeskStayId: $execution->front_desk_stay_id,
            reservationId: $execution->reservation_id,
            checkoutExecutionId: $execution->id,
            idempotencyKey: $execution->idempotency_key,
            terminalStatus: $execution->terminal_stay_status?->value ?? FrontDeskStayStatusEnum::CheckedOut->value,
            businessDate: $execution->business_date?->format('Y-m-d') ?? '',
            occurredAt: $execution->occurred_at?->toISOString() ?? '',
            handoffId: $handoff->id,
            handoffDeliveryStatus: $handoff->delivery_status?->value ?? FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending->value,
            nightAuditStatus: (string) $execution->night_audit_source_status,
            pmsTerminalFinancialStatus: (string) $execution->pms_financial_attestation_status,
            generalCashierTerminalObligationStatus: (string) $execution->general_cashier_attestation_status,
            replayed: $replayed,
        );
    }

    private function assertClaimMatchesPreflight(CheckoutSensitiveConfirmationClaimResult $claim, CheckoutSensitiveConfirmationPreflightResult $preflight): void
    {
        if ($claim->issuanceId !== $preflight->issuanceId
            || $claim->confirmationIdentity !== $preflight->confirmationIdentity
            || $claim->confirmationFingerprint !== $preflight->confirmationFingerprint
            || $claim->actorId !== $preflight->actorId
            || $claim->companyId !== $preflight->companyId
            || $claim->propertyId !== $preflight->propertyId
            || $claim->frontDeskStayId !== $preflight->frontDeskStayId
            || $claim->checkoutIdempotencyKey !== $preflight->checkoutIdempotencyKey
            || ! $claim->confirmedAt->equalTo($preflight->confirmedAt)
            || ! $claim->expiresAt->equalTo($preflight->expiresAt)) {
            throw new DomainException(self::ERROR_CONFIRMATION_CHANGED);
        }
    }

    private function cleanupConfirmationSessionAfterCommit(FrontDeskCheckoutExecutionResult $result, string $propertyId, string $stayId): void
    {
        try {
            $this->confirmation->cleanupCurrentSessionReference();
        } catch (\Throwable $exception) {
            Log::warning('Checkout confirmation session cleanup failed after committed checkout.', [
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stayId,
                'checkout_execution_id' => $result->checkoutExecutionId,
                'exception_class' => $exception::class,
            ]);
        }
    }

    private function postgresWallClockUtc(): Carbon
    {
        $row = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS wall_clock_utc");

        return Carbon::parse($row->wall_clock_utc);
    }

    private function executionSourceHash(
        string $propertyId,
        string $stayId,
        string $reservationId,
        string $idempotencyKey,
        string $finalReviewId,
        string $businessDateId,
        string $businessDate,
        string $nightAuditStatus,
        string $nightAuditFingerprint,
        string $financialStatus,
        string $financialFingerprint,
        string $cashierStatus,
        string $cashierFingerprint,
        CheckoutSensitiveConfirmationClaimResult $claim,
        string $actorId,
        Carbon $occurredAt,
    ): string {
        return hash('sha256', json_encode([
            'property_id' => $propertyId,
            'front_desk_stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'idempotency_key' => $idempotencyKey,
            'front_desk_final_review_id' => $finalReviewId,
            'property_business_date_id' => $businessDateId,
            'business_date' => $businessDate,
            'night_audit_source_status' => $nightAuditStatus,
            'night_audit_source_fingerprint' => $nightAuditFingerprint,
            'pms_financial_attestation_status' => $financialStatus,
            'pms_financial_attestation_fingerprint' => $financialFingerprint,
            'general_cashier_attestation_status' => $cashierStatus,
            'general_cashier_attestation_fingerprint' => $cashierFingerprint,
            'confirmation_consumption_id' => $claim->consumptionId,
            'confirmation_fingerprint' => $claim->confirmationFingerprint,
            'confirmation_consumed_at' => $claim->consumedAt->toISOString(),
            'actor_id' => $actorId,
            'occurred_at' => $occurredAt->toISOString(),
        ], JSON_UNESCAPED_SLASHES));
    }

    private function isRetryableSqlState(QueryException $exception): bool
    {
        $sqlState = isset($exception->errorInfo[0]) ? (string) $exception->errorInfo[0] : (string) $exception->getCode();

        return in_array($sqlState, ['40001', '40P01'], true);
    }

    private function mapQueryException(QueryException $exception): never
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'fd_ce_idempotency_unique')) {
            throw new ConflictHttpException(self::ERROR_IDEMPOTENCY_CONFLICT, $exception);
        }

        if (str_contains($message, 'fd_ce_stay_unique')) {
            throw new ConflictHttpException(self::ERROR_ALREADY_COMPLETED, $exception);
        }

        throw $exception;
    }
}
