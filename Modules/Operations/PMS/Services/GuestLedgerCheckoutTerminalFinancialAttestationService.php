<?php

namespace Modules\Operations\PMS\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation;
use RuntimeException;
use Throwable;

/**
 * PMS Guest Ledger / PMS Cashiering — Terminal Financial Attestation Service (GLF-E).
 *
 * Transaction-participating attestation. Requires an already-active PostgreSQL
 * transaction with a valid NA-A2 operational lock context.
 *
 * Performs zero writes. Locks PMS-owned rows with FOR UPDATE and holds them
 * until caller commit or rollback. Returns an immutable attestation value object
 * that is valid only inside the issuing transaction.
 */
class GuestLedgerCheckoutTerminalFinancialAttestationService
{
    public const ERROR_REQUIRES_ACTIVE_TRANSACTION = 'GLF_E_REQUIRES_ACTIVE_TRANSACTION';
    public const ERROR_POSTGRESQL_REQUIRED = 'GLF_E_POSTGRESQL_REQUIRED';
    public const ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT = 'GLF_E_INVALID_OPERATIONAL_LOCK_CONTEXT';
    public const ERROR_PROPERTY_STAY_CONTEXT_CONFLICT = 'GLF_E_PROPERTY_STAY_CONTEXT_CONFLICT';
    public const ERROR_FINANCIAL_SOURCE_LOCK_TIMEOUT = 'GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT';
    public const ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION = 'GLF_E_INVALID_TERMINAL_FINANCIAL_ATTESTATION';

    private const LOCK_TIMEOUT = '5s';
    private const LOCK_TIMEOUT_SQLSTATE = '55P03';
    private const GLF_E_CAPABILITY_SETTING = 'ivorq.glf_e_attestation_capability';

    /**
     * @var \WeakMap<GuestLedgerCheckoutTerminalFinancialAttestation, array<string, mixed>>|null
     */
    private static ?\WeakMap $issuedAttestations = null;

    public function __construct(
        private readonly GuestLedgerCheckoutFinancialEvaluationService $evaluator,
        private readonly PropertyBusinessDateOperationalLockService $operationalLockService,
        private readonly GuestLedgerPostingCompletenessParticipationPort $postingCompletenessPort,
        private readonly GuestLedgerSettlementHoldParticipationPort $settlementHoldPort,
        private readonly GuestLedgerCompletedSettlementConflictParticipationPort $completedSettlementPort,
    ) {}

    /**
     * Produce a terminal financial attestation inside the caller's active
     * PostgreSQL transaction. Requires a valid NA-A2 operational lock context.
     *
     * @throws RuntimeException|DomainException
     */
    public function attest(
        PropertyBusinessDateOperationalLockContext $operationalContext,
        string $frontDeskStayId,
    ): GuestLedgerCheckoutTerminalFinancialAttestation {
        // 1. Require active Laravel transaction
        $this->assertParticipatingPostgresTransaction();

        // 2. Validate NA-A2 context (before any PMS source query)
        try {
            $this->operationalLockService->assertIssuedForCurrentTransaction($operationalContext);
        } catch (DomainException $e) {
            throw new DomainException(
                self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT,
                0,
                $e,
            );
        }

        // 3. Set transaction-local lock timeout
        DB::statement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");

        // 4. Resolve stay and validate same-property relationship
        $propertyId = $operationalContext->property_id;
        $stay = DB::table('front_desk_stays')
            ->where('id', $frontDeskStayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_CONTEXT_CONFLICT);
        }

        // 5. Locked terminal evaluation
        try {
            $result = $this->evaluator->evaluateLockedTerminal(
                $frontDeskStayId,
                $propertyId,
                $this->postingCompletenessPort,
                $this->settlementHoldPort,
                $this->completedSettlementPort,
            );
        } catch (QueryException $exception) {
            if ($this->isLockTimeout($exception)) {
                throw new RuntimeException(self::ERROR_FINANCIAL_SOURCE_LOCK_TIMEOUT, 0, $exception);
            }
            throw $exception;
        }

        // 6. Re-validate stay→reservation→guest same-property (from locked rows)
        $reservationId = $result['reservation_id'];
        if (empty($reservationId)
            || $stay->reservation_id !== $reservationId
            || $stay->guest_id !== $result['guest_id']) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_CONTEXT_CONFLICT);
        }

        // 7. Build status enum
        $status = GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::from($result['status_value']);

        // 8. Postgres transaction proof for fingerprint
        $transactionId = $this->postgresTransactionId();

        // 9. Build terminal fingerprint
        $fingerprint = $this->evaluator->buildTerminalFingerprint(
            $propertyId, $frontDeskStayId, $reservationId,
            $operationalContext->property_business_date_id, $operationalContext->business_date,
            $result['folio_facts'], $result['payment_facts'], $result['deposit_facts'],
            $result['refund_facts'], $result['ar_facts'], $result['port_facts'],
            $result['cash_linked_references'], $result['currency'], $result['status_value'],
            $transactionId,
        );

        // 10. Create immutable attestation
        $evaluatedAt = CarbonImmutable::now('UTC')->toISOString();

        $attestation = GuestLedgerCheckoutTerminalFinancialAttestation::create(
            status: $status,
            property_id: $propertyId,
            property_business_date_id: $operationalContext->property_business_date_id,
            business_date: $operationalContext->business_date,
            front_desk_stay_id: $frontDeskStayId,
            reservation_id: $reservationId,
            folio_count: $result['folio_count'],
            canonical_aggregate_balance: $result['canonical_aggregate_balance'],
            currency: $result['currency'],
            blocker_codes: $result['blocker_codes'],
            review_reasons: $result['review_reasons'],
            evidence_unavailable_codes: $result['evidence_unavailable_codes'],
            cash_linked_references: $result['cash_linked_references'],
            cashier_session_ids: $result['cashier_session_ids'],
            source_fingerprint: $fingerprint,
            evaluated_at: $evaluatedAt,
            markers: $result['markers'],
        );

        // 11. Issue transaction-local GLF-E capability after all locks and evaluation
        $capabilityHash = $this->issueGlfeCapability();

        // 12. Register in exact-object WeakMap
        $proof = $this->postgresTransactionProof();
        self::issuedAttestations()[$attestation] = [
            'postgres_backend_pid' => $proof['backend_pid'],
            'postgres_transaction_id' => $proof['transaction_id'],
            'operational_context' => $operationalContext,
            'property_id' => $propertyId,
            'property_business_date_id' => $operationalContext->property_business_date_id,
            'business_date' => $operationalContext->business_date,
            'stay_id' => $frontDeskStayId,
            'reservation_id' => $reservationId,
            'status' => $status->value,
            'source_fingerprint' => $fingerprint,
            'attestation_capability_hash' => $capabilityHash,
        ];

        return $attestation;
    }

    /**
     * Validate that an attestation was issued for the current transaction
     * and the exact operational context. For future General Cashier consumption.
     *
     * @throws DomainException
     */
    public function assertIssuedForCurrentTransaction(
        PropertyBusinessDateOperationalLockContext $operationalContext,
        GuestLedgerCheckoutTerminalFinancialAttestation $attestation,
    ): void {
        // 1. Revalidate NA-A2 operational context (wrap in GLF-E error)
        try {
            $this->operationalLockService->assertIssuedForCurrentTransaction($operationalContext);
        } catch (DomainException $e) {
            throw new DomainException(
                self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                0,
                $e,
            );
        }

        // 2. Require exact attestation object in WeakMap
        $issuedAttestations = self::issuedAttestations();
        if (! isset($issuedAttestations[$attestation])) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }

        $issuance = $issuedAttestations[$attestation];

        // 3. Require same operational-context object identity
        if ($issuance['operational_context'] !== $operationalContext) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }

        // 4. Require same backend PID, transaction ID, and transaction-local capability
        $currentProof = $this->glfeCapabilityProof();
        if ($currentProof['backend_pid'] !== $issuance['postgres_backend_pid']
            || $currentProof['transaction_id'] !== $issuance['postgres_transaction_id']
            || ! hash_equals(
                (string) $issuance['attestation_capability_hash'],
                hash('sha256', $currentProof['capability_token'])
            )) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }

        // 5. Require all authoritative identities and fingerprint to match
        if ($attestation->property_id !== $issuance['property_id']
            || $attestation->property_business_date_id !== $issuance['property_business_date_id']
            || $attestation->business_date !== $issuance['business_date']
            || $attestation->front_desk_stay_id !== $issuance['stay_id']
            || $attestation->reservation_id !== $issuance['reservation_id']
            || $attestation->status->value !== $issuance['status']
            || $attestation->source_fingerprint !== $issuance['source_fingerprint']) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // Transaction guards
    // ═════════════════════════════════════════════════════════════════════

    private function assertParticipatingPostgresTransaction(): void
    {
        if (DB::transactionLevel() < 1) {
            throw new RuntimeException(self::ERROR_REQUIRES_ACTIVE_TRANSACTION);
        }

        if (DB::connection()->getDriverName() !== 'pgsql') {
            throw new RuntimeException(self::ERROR_POSTGRESQL_REQUIRED);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // PostgreSQL transaction proof
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return array{backend_pid: int, transaction_id: string}
     */
    private function postgresTransactionProof(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id'
            );
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT, 0, $exception);
        }

        $backendPid = (int) ($row->backend_pid ?? 0);
        $transactionId = trim((string) ($row->transaction_id ?? ''));

        if ($backendPid < 1 || $transactionId === '') {
            throw new DomainException(self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT);
        }

        return [
            'backend_pid' => $backendPid,
            'transaction_id' => $transactionId,
        ];
    }

    private function postgresTransactionId(): string
    {
        try {
            $row = DB::selectOne("SELECT txid_current()::text AS transaction_id");
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT, 0, $exception);
        }

        $transactionId = trim((string) ($row->transaction_id ?? ''));
        if ($transactionId === '') {
            throw new DomainException(self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT);
        }

        return $transactionId;
    }

    // ═════════════════════════════════════════════════════════════════════
    // GLF-E transaction-local capability
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Issue a cryptographically random transaction-local capability token
     * after all PMS locks and terminal evaluation have succeeded.
     * Only the SHA-256 hash is retained.
     */
    private function issueGlfeCapability(): string
    {
        try {
            $capabilityToken = bin2hex(random_bytes(32));
            $row = DB::selectOne(
                'SELECT set_config(?, ?, true) AS capability_token',
                [self::GLF_E_CAPABILITY_SETTING, $capabilityToken]
            );
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION, 0, $exception);
        }

        $configuredToken = trim((string) ($row->capability_token ?? ''));
        if ($configuredToken === '' || ! hash_equals($capabilityToken, $configuredToken)) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }

        // Replacing this transaction-local setting makes only the newest attestation valid.
        return hash('sha256', $capabilityToken);
    }

    /**
     * @return array{backend_pid: int, transaction_id: string, capability_token: string}
     */
    private function glfeCapabilityProof(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id, '
                . 'current_setting(?, true) AS capability_token',
                [self::GLF_E_CAPABILITY_SETTING]
            );
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION, 0, $exception);
        }

        $backendPid = (int) ($row->backend_pid ?? 0);
        $transactionId = trim((string) ($row->transaction_id ?? ''));
        $capabilityToken = trim((string) ($row->capability_token ?? ''));

        if ($backendPid < 1 || $transactionId === '' || $capabilityToken === '') {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);
        }

        return [
            'backend_pid' => $backendPid,
            'transaction_id' => $transactionId,
            'capability_token' => $capabilityToken,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Exact-object issuance registry
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @return \WeakMap<GuestLedgerCheckoutTerminalFinancialAttestation, array<string, mixed>>
     */
    private static function issuedAttestations(): \WeakMap
    {
        return self::$issuedAttestations ??= new \WeakMap();
    }

    // ═════════════════════════════════════════════════════════════════════
    // Narrow lock-timeout detection
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Only map a PostgreSQL lock timeout when BOTH conditions are met:
     *   - SQLSTATE = 55P03
     *   - PostgreSQL message contains "canceling statement due to lock timeout"
     *
     * A different 55P03, deadlock, serialization failure, or unknown
     * QueryException must propagate unchanged.
     */
    private function isLockTimeout(Throwable $exception): bool
    {
        $sqlState = null;
        if (isset($exception->errorInfo) && is_array($exception->errorInfo) && isset($exception->errorInfo[0])) {
            $sqlState = (string) $exception->errorInfo[0];
        } else {
            $sqlState = (string) $exception->getCode() ?: '';
        }

        if ($sqlState !== self::LOCK_TIMEOUT_SQLSTATE) {
            return false;
        }

        $message = $exception->getMessage();

        return str_contains($message, 'canceling statement due to lock timeout');
    }
}
