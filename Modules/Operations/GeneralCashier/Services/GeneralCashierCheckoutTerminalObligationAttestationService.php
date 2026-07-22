<?php

namespace Modules\Operations\GeneralCashier\Services;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;
use Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation;
use RuntimeException;
use Throwable;

/**
 * General Cashier — Terminal Obligation Attestation Service (GC-A2).
 *
 * Transaction-participating attestation. Requires an already-active PostgreSQL
 * transaction with a valid NA-A2 operational lock context and a valid GLF-E
 * terminal financial attestation.
 *
 * Performs zero writes. Locks General Cashier-owned cashier_sessions rows
 * with FOR UPDATE and holds them until caller commit or rollback. Returns
 * an immutable attestation value object that is valid only inside the
 * issuing transaction.
 *
 * GC-A1 must NOT be called or imported by this service.
 */
class GeneralCashierCheckoutTerminalObligationAttestationService
{
    public const ERROR_REQUIRES_ACTIVE_TRANSACTION = 'GC_A2_REQUIRES_ACTIVE_TRANSACTION';
    public const ERROR_POSTGRESQL_REQUIRED = 'GC_A2_POSTGRESQL_REQUIRED';
    public const ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT = 'GC_A2_INVALID_OPERATIONAL_LOCK_CONTEXT';
    public const ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION = 'GC_A2_INVALID_TERMINAL_FINANCIAL_ATTESTATION';
    public const ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT = 'GC_A2_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT';
    public const ERROR_INVALID_CASH_LINKED_REFERENCES = 'GC_A2_INVALID_CASH_LINKED_REFERENCES';
    public const ERROR_CASHIER_SOURCE_LOCK_TIMEOUT = 'GC_A2_CASHIER_SOURCE_LOCK_TIMEOUT';
    public const ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION = 'GC_A2_INVALID_TERMINAL_OBLIGATION_ATTESTATION';

    private const LOCK_TIMEOUT = '5s';
    private const LOCK_TIMEOUT_SQLSTATE = '55P03';
    private const GC_A2_CAPABILITY_SETTING = 'ivorq.gc_a2_attestation_capability';

    private const ALLOWED_SOURCE_TYPES = [
        'GUEST_PAYMENT_TRANSACTION',
        'GUEST_DEPOSIT_TRANSACTION',
        'GUEST_REFUND_TRANSACTION',
    ];

    /**
     * @var \WeakMap<GeneralCashierCheckoutTerminalObligationAttestation, array<string, mixed>>|null
     */
    private static ?\WeakMap $issuedAttestations = null;

    public function __construct(
        private readonly PropertyBusinessDateOperationalLockService $operationalLockService,
        private readonly GuestLedgerCheckoutTerminalFinancialAttestationService $financialAttestationService,
    ) {}

    /**
     * Produce a terminal obligation attestation inside the caller's active
     * PostgreSQL transaction. Requires a valid NA-A2 operational lock context
     * and a valid GLF-E terminal financial attestation.
     *
     * @throws RuntimeException|DomainException
     */
    public function attest(
        PropertyBusinessDateOperationalLockContext $operationalContext,
        GuestLedgerCheckoutTerminalFinancialAttestation $financialAttestation,
    ): GeneralCashierCheckoutTerminalObligationAttestation {
        // 1. Require active Laravel transaction
        $this->assertParticipatingPostgresTransaction();

        // 2. Validate exact NA-A2 operational context (before any GC query)
        try {
            $this->operationalLockService->assertIssuedForCurrentTransaction($operationalContext);
        } catch (DomainException $e) {
            throw new DomainException(self::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT, 0, $e);
        }

        // 3. Validate exact transaction-bound GLF-E attestation (before any GC query)
        try {
            $this->financialAttestationService->assertIssuedForCurrentTransaction(
                $operationalContext,
                $financialAttestation,
            );
        } catch (DomainException $e) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION, 0, $e);
        }

        // 4. Verify context identities against GLF-E identities
        if ($operationalContext->property_id !== $financialAttestation->property_id
            || $operationalContext->property_business_date_id !== $financialAttestation->property_business_date_id
            || $operationalContext->business_date !== $financialAttestation->business_date) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT);
        }

        $propertyId = $operationalContext->property_id;
        $stayId = $financialAttestation->front_desk_stay_id;
        $reservationId = $financialAttestation->reservation_id;

        // 5. Re-resolve Front Desk stay and PMS reservation relationships read-only
        $stay = DB::table('front_desk_stays')
            ->where('id', $stayId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $stay) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT);
        }

        $reservation = DB::table('reservations')
            ->where('id', $reservationId)
            ->where('property_id', $propertyId)
            ->first();

        if (! $reservation) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT);
        }

        // Verify stay ↔ reservation ↔ guest relationship
        if ((string) $stay->reservation_id !== $reservationId
            || (string) $stay->guest_id !== (string) ($reservation->primary_guest_id ?? '')) {
            throw new DomainException(self::ERROR_PROPERTY_STAY_RESERVATION_CONTEXT_CONFLICT);
        }

        // 6. Validate minimized GLF-E cash-linked reference structure
        $this->validateCashLinkedReferences($financialAttestation);

        $consumedPmsStatus = $financialAttestation->status->value;
        $consumedPmsFingerprint = $financialAttestation->source_fingerprint;
        $cashierSessionIds = $financialAttestation->cashier_session_ids;

        // 7. Set narrow transaction-local lock timeout
        DB::statement("SET LOCAL lock_timeout = '" . self::LOCK_TIMEOUT . "'");

        // 8 & 9. Lock and evaluate General Cashier sessions
        try {
            $evaluationResult = $this->evaluateTerminalObligations(
                $propertyId,
                $cashierSessionIds,
                $financialAttestation->cash_linked_references,
            );
        } catch (QueryException $exception) {
            if ($this->isLockTimeout($exception)) {
                throw new RuntimeException(self::ERROR_CASHIER_SOURCE_LOCK_TIMEOUT, 0, $exception);
            }
            throw $exception;
        }

        // 10. Build immutable transaction-bound value object
        $evaluatedAt = CarbonImmutable::now('UTC')->toISOString();

        $attestation = GeneralCashierCheckoutTerminalObligationAttestation::create(
            status: $evaluationResult['status'],
            property_id: $propertyId,
            property_business_date_id: $operationalContext->property_business_date_id,
            business_date: $operationalContext->business_date,
            front_desk_stay_id: $stayId,
            reservation_id: $reservationId,
            consumed_pms_status: $consumedPmsStatus,
            consumed_pms_source_fingerprint: $consumedPmsFingerprint,
            cashier_session_ids: $cashierSessionIds,
            cash_linked_reference_count: count($financialAttestation->cash_linked_references),
            blocker_codes: $evaluationResult['blocker_codes'],
            review_reasons: $evaluationResult['review_reasons'],
            evidence_unavailable_codes: $evaluationResult['evidence_unavailable_codes'],
            source_fingerprint: $this->buildSourceFingerprint(
                $propertyId,
                $operationalContext->property_business_date_id,
                $operationalContext->business_date,
                $stayId,
                $reservationId,
                $consumedPmsStatus,
                $consumedPmsFingerprint,
                $financialAttestation->cash_linked_references,
                $evaluationResult['locked_session_facts'],
                $evaluationResult['status']->value,
                $evaluationResult['blocker_codes'],
                $evaluationResult['review_reasons'],
                $evaluationResult['evidence_unavailable_codes'],
                $evaluationResult['markers'],
            ),
            evaluated_at: $evaluatedAt,
            markers: $evaluationResult['markers'],
        );

        // 11. Issue GC-A2 transaction-local capability after all locks and evaluation
        $capabilityHash = $this->issueGcA2Capability();

        // 12. Register exact object issuance in private WeakMap
        $proof = $this->postgresTransactionProof();
        self::issuedAttestations()[$attestation] = [
            'postgres_backend_pid' => $proof['backend_pid'],
            'postgres_transaction_id' => $proof['transaction_id'],
            'operational_context' => $operationalContext,
            'financial_attestation' => $financialAttestation,
            'property_id' => $propertyId,
            'property_business_date_id' => $operationalContext->property_business_date_id,
            'business_date' => $operationalContext->business_date,
            'stay_id' => $stayId,
            'reservation_id' => $reservationId,
            'consumed_pms_status' => $consumedPmsStatus,
            'consumed_pms_source_fingerprint' => $consumedPmsFingerprint,
            'status' => $evaluationResult['status']->value,
            'source_fingerprint' => $attestation->source_fingerprint,
            'attestation_capability_hash' => $capabilityHash,
        ];

        return $attestation;
    }

    /**
     * Validate that a GC-A2 attestation was issued for the current transaction
     * and the exact operational context and financial attestation.
     *
     * @throws DomainException
     */
    public function assertIssuedForCurrentTransaction(
        PropertyBusinessDateOperationalLockContext $operationalContext,
        GuestLedgerCheckoutTerminalFinancialAttestation $financialAttestation,
        GeneralCashierCheckoutTerminalObligationAttestation $cashierAttestation,
    ): void {
        // 1. Revalidate NA-A2 operational context (wrap in GC-A2 error)
        try {
            $this->operationalLockService->assertIssuedForCurrentTransaction($operationalContext);
        } catch (DomainException $e) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION, 0, $e);
        }

        // 2. Revalidate GLF-E attestation
        try {
            $this->financialAttestationService->assertIssuedForCurrentTransaction(
                $operationalContext,
                $financialAttestation,
            );
        } catch (DomainException $e) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION, 0, $e);
        }

        // 3. Require exact GC-A2 attestation object in WeakMap
        $issuedAttestations = self::issuedAttestations();
        if (! isset($issuedAttestations[$cashierAttestation])) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
        }

        $issuance = $issuedAttestations[$cashierAttestation];

        // 4. Require same NA-A2 and GLF-E object identities
        if ($issuance['operational_context'] !== $operationalContext
            || $issuance['financial_attestation'] !== $financialAttestation) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
        }

        // 5. Require same backend PID, transaction ID, and transaction-local capability
        $currentProof = $this->gcA2CapabilityProof();
        if ($currentProof['backend_pid'] !== $issuance['postgres_backend_pid']
            || $currentProof['transaction_id'] !== $issuance['postgres_transaction_id']
            || ! hash_equals(
                (string) $issuance['attestation_capability_hash'],
                hash('sha256', $currentProof['capability_token'])
            )) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
        }

        // 6. Require all authoritative immutable fields to match
        if ($cashierAttestation->property_id !== $issuance['property_id']
            || $cashierAttestation->property_business_date_id !== $issuance['property_business_date_id']
            || $cashierAttestation->business_date !== $issuance['business_date']
            || $cashierAttestation->front_desk_stay_id !== $issuance['stay_id']
            || $cashierAttestation->reservation_id !== $issuance['reservation_id']
            || $cashierAttestation->consumed_pms_status !== $issuance['consumed_pms_status']
            || $cashierAttestation->consumed_pms_source_fingerprint !== $issuance['consumed_pms_source_fingerprint']
            || $cashierAttestation->source_fingerprint !== $issuance['source_fingerprint']) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
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
    // GLF-E cash-linked reference validation (minimized)
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Validate minimized GLF-E cash-linked reference structure.
     * Must fail before any cashier_sessions query.
     *
     * @throws DomainException
     */
    private function validateCashLinkedReferences(
        GuestLedgerCheckoutTerminalFinancialAttestation $financialAttestation,
    ): void {
        $references = $financialAttestation->cash_linked_references;

        // When GLF-E explicitly signals evidence-unavailable, fail closed
        if (in_array('CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE', $financialAttestation->evidence_unavailable_codes, true)) {
            throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
        }

        // Empty references are valid — no cashier obligation
        if (count($references) === 0) {
            return;
        }

        $seenTuples = [];
        $derivedSessionIds = [];

        foreach ($references as $ref) {
            // Every reference must be an array with exactly the expected keys
            if (! is_array($ref)) {
                throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
            }

            $allowedKeys = ['source_type', 'source_id', 'cashier_session_id'];
            $actualKeys = array_keys($ref);
            sort($actualKeys);
            if ($actualKeys !== $allowedKeys) {
                throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
            }

            $sourceType = trim((string) ($ref['source_type'] ?? ''));
            $sourceId = trim((string) ($ref['source_id'] ?? ''));
            $cashierSessionId = trim((string) ($ref['cashier_session_id'] ?? ''));

            // All three values must be non-empty strings
            if ($sourceType === '' || $sourceId === '' || $cashierSessionId === '') {
                throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
            }

            // Source-type allowlist
            if (! in_array($sourceType, self::ALLOWED_SOURCE_TYPES, true)) {
                throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
            }

            // No duplicate exact tuples
            $tupleKey = "{$sourceType}|{$sourceId}|{$cashierSessionId}";
            if (isset($seenTuples[$tupleKey])) {
                throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
            }
            $seenTuples[$tupleKey] = true;

            $derivedSessionIds[] = $cashierSessionId;
        }

        // Derived unique sorted session IDs must match GLF-E cashier_session_ids
        $derivedSessionIds = array_values(array_unique($derivedSessionIds));
        sort($derivedSessionIds);

        $attestedSessionIds = $financialAttestation->cashier_session_ids;
        sort($attestedSessionIds);

        if ($derivedSessionIds !== $attestedSessionIds) {
            throw new DomainException(self::ERROR_INVALID_CASH_LINKED_REFERENCES);
        }
    }

    // ═════════════════════════════════════════════════════════════════════
    // General Cashier terminal obligation evaluation
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Lock cashier_sessions deterministically and evaluate terminal obligations.
     *
     * @param string[] $cashierSessionIds
     * @param array<int, array<string, string>> $cashLinkedReferences
     * @return array{status: GeneralCashierCheckoutTerminalObligationAttestationStatusEnum, blocker_codes: string[], review_reasons: string[], evidence_unavailable_codes: string[], locked_session_facts: array[], markers: array<string, string>}
     */
    private function evaluateTerminalObligations(
        string $propertyId,
        array $cashierSessionIds,
        array $cashLinkedReferences,
    ): array {
        $cashLinkedReferenceCount = count($cashLinkedReferences);

        // Base markers
        $markers = [
            'attestation_owner' => 'GENERAL_CASHIER',
            'transaction_boundary' => 'ACTIVE_POSTGRESQL_TRANSACTION',
            'pms_reference_contract' => 'EXACT_GLF_E_ATTESTATION',
        ];

        // No linked cashier sessions → CLEAR
        if ($cashLinkedReferenceCount === 0 && count($cashierSessionIds) === 0) {
            $markers['cashier_obligation_scope_marker'] = 'NO_AUTHORITATIVE_CASHIER_OBLIGATIONS';
            $markers['cashier_accountability_marker'] = 'CASHIER_ACCOUNTABILITY_CLEAR';

            return [
                'status' => GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear,
                'blocker_codes' => [],
                'review_reasons' => [],
                'evidence_unavailable_codes' => [],
                'locked_session_facts' => [],
                'markers' => $markers,
            ];
        }

        $markers['cashier_obligation_scope_marker'] = 'AUTHORITATIVE_CASHIER_OBLIGATIONS_FOUND';

        // Lock cashier_sessions deterministically: property_id, id ASC, FOR UPDATE
        $ids = array_values(array_unique($cashierSessionIds));
        sort($ids);

        $lockedSessions = DB::table('cashier_sessions')
            ->where('property_id', $propertyId)
            ->whereIn('id', $ids)
            ->orderBy('property_id')
            ->orderBy('id')
            ->lockForUpdate()
            ->get();

        $lockedSessionFacts = [];
        $blockerCodes = [];
        $reviewReasons = [];
        $evidenceUnavailableCodes = [];

        // Map locked sessions by ID
        $lockedById = [];
        foreach ($lockedSessions as $session) {
            $lockedById[(string) $session->id] = $session;
        }

        // Check each referenced session
        foreach ($ids as $sessionId) {
            if (! isset($lockedById[$sessionId])) {
                // Missing authoritative session row
                $evidenceUnavailableCodes[] = 'CASHIER_SESSION_SOURCE_EVIDENCE_UNAVAILABLE';
                continue;
            }

            $session = $lockedById[$sessionId];
            $status = trim((string) ($session->status ?? ''));

            // Collect canonical session facts
            $lockedSessionFacts[] = [
                'id' => (string) $session->id,
                'cashier_user_id' => (string) ($session->cashier_user_id ?? ''),
                'status' => $status,
                'opened_at' => $session->opened_at ?? null,
                'opened_by' => (string) ($session->opened_by ?? ''),
                'closed_at' => $session->closed_at ?? null,
                'closed_by' => (string) ($session->closed_by ?? ''),
            ];

            // OPEN session → BLOCKED
            if ($status === 'OPEN') {
                $blockerCodes[] = 'CASHIER_SESSION_OPEN';
                continue;
            }

            // CLOSED session — evaluate close evidence
            if ($status === 'CLOSED') {
                $closedAt = $session->closed_at ?? null;
                $closedBy = trim((string) ($session->closed_by ?? ''));

                // Missing close evidence
                if ($closedAt === null || $closedBy === '') {
                    $evidenceUnavailableCodes[] = 'CASHIER_SESSION_CLOSE_EVIDENCE_UNAVAILABLE';
                    continue;
                }

                // CLOSED with complete close fields but no accountability evidence
                $evidenceUnavailableCodes[] = 'CASHIER_SESSION_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE';
                continue;
            }

            // Unsupported persisted session status
            $evidenceUnavailableCodes[] = 'CASHIER_SESSION_STATUS_EVIDENCE_UNAVAILABLE';
        }

        // Add CASH_LINKED_REFERENCE_EVIDENCE_UNAVAILABLE from GLF-E if present
        // (already validated above — but if it slipped through, record it)
        // Note: We validated this before the lock query, so this is a belt-and-suspenders check

        // Determine terminal status by precedence
        $blockerCodes = array_values(array_unique($blockerCodes));
        $reviewReasons = array_values(array_unique($reviewReasons));
        $evidenceUnavailableCodes = array_values(array_unique($evidenceUnavailableCodes));

        sort($blockerCodes);
        sort($reviewReasons);
        sort($evidenceUnavailableCodes);

        // Sort session facts by id
        usort($lockedSessionFacts, fn(array $a, array $b): int => $a['id'] <=> $b['id']);

        if (count($evidenceUnavailableCodes) > 0) {
            $status = GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationEvidenceUnavailable;
            $markers['cashier_accountability_marker'] = 'CASHIER_ACCOUNTABILITY_EVIDENCE_UNAVAILABLE';
        } elseif (count($reviewReasons) > 0) {
            $status = GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationReviewRequired;
            $markers['cashier_accountability_marker'] = 'CASHIER_ACCOUNTABILITY_REVIEW_REQUIRED';
        } elseif (count($blockerCodes) > 0) {
            $status = GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationBlocked;
            $markers['cashier_accountability_marker'] = 'CASHIER_ACCOUNTABILITY_BLOCKED';
        } else {
            $status = GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear;
            $markers['cashier_accountability_marker'] = 'CASHIER_ACCOUNTABILITY_CLEAR';
        }

        return [
            'status' => $status,
            'blocker_codes' => $blockerCodes,
            'review_reasons' => $reviewReasons,
            'evidence_unavailable_codes' => $evidenceUnavailableCodes,
            'locked_session_facts' => $lockedSessionFacts,
            'markers' => $markers,
        ];
    }

    // ═════════════════════════════════════════════════════════════════════
    // Deterministic GC-A2 source fingerprint
    // ═════════════════════════════════════════════════════════════════════

    /**
     * @param array<int, array<string, string>> $cashLinkedReferences
     * @param array<int, array<string, mixed>> $lockedSessionFacts
     * @param string[] $blockerCodes
     * @param string[] $reviewReasons
     * @param string[] $evidenceUnavailableCodes
     * @param array<string, string> $markers
     */
    private function buildSourceFingerprint(
        string $propertyId,
        string $propertyBusinessDateId,
        string $businessDate,
        string $stayId,
        string $reservationId,
        string $consumedPmsStatus,
        string $consumedPmsFingerprint,
        array $cashLinkedReferences,
        array $lockedSessionFacts,
        string $statusValue,
        array $blockerCodes,
        array $reviewReasons,
        array $evidenceUnavailableCodes,
        array $markers,
    ): string {
        // Hash cash-linked references canonically
        $refHashes = [];
        foreach ($cashLinkedReferences as $ref) {
            $refHashes[] = hash('sha256', "{$ref['source_type']}|{$ref['source_id']}|{$ref['cashier_session_id']}");
        }
        sort($refHashes);
        $cashRefsHash = hash('sha256', implode('|', $refHashes));

        // Canonical session facts (restricted to allowed fields only)
        $sessionFactHashes = [];
        foreach ($lockedSessionFacts as $fact) {
            $sessionFactHashes[] = hash('sha256', implode('|', [
                $fact['id'],
                (string) ($fact['cashier_user_id'] ?? ''),
                (string) ($fact['status'] ?? ''),
                $fact['opened_at'] ?? '',
                (string) ($fact['opened_by'] ?? ''),
                $fact['closed_at'] ?? '',
                (string) ($fact['closed_by'] ?? ''),
            ]));
        }
        sort($sessionFactHashes);
        $sessionsHash = hash('sha256', implode('|', $sessionFactHashes));

        // One-way SHA-256 hash of PostgreSQL transaction ID
        $txidHash = hash('sha256', $this->postgresTransactionId());

        // Sort all arrays
        sort($blockerCodes);
        sort($reviewReasons);
        sort($evidenceUnavailableCodes);
        ksort($markers);

        // Build canonical fingerprint structure
        $canonical = implode('|', [
            GeneralCashierCheckoutTerminalObligationAttestation::VERSION,
            $propertyId,
            $propertyBusinessDateId,
            $businessDate,
            $stayId,
            $reservationId,
            $consumedPmsStatus,
            $consumedPmsFingerprint,
            $cashRefsHash,
            $sessionsHash,
            $statusValue,
            implode(',', $blockerCodes),
            implode(',', $reviewReasons),
            implode(',', $evidenceUnavailableCodes),
            json_encode($markers),
            $txidHash,
        ]);

        return hash('sha256', $canonical);
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
    // GC-A2 transaction-local capability
    // ═════════════════════════════════════════════════════════════════════

    /**
     * Issue a cryptographically random transaction-local capability token
     * after all General Cashier locks and evaluation have succeeded.
     * Only the SHA-256 hash is retained.
     */
    private function issueGcA2Capability(): string
    {
        try {
            $capabilityToken = bin2hex(random_bytes(32));
            $row = DB::selectOne(
                'SELECT set_config(?, ?, true) AS capability_token',
                [self::GC_A2_CAPABILITY_SETTING, $capabilityToken]
            );
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION, 0, $exception);
        }

        $configuredToken = trim((string) ($row->capability_token ?? ''));
        if ($configuredToken === '' || ! hash_equals($capabilityToken, $configuredToken)) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
        }

        // Replacing this transaction-local setting makes only the newest attestation valid.
        return hash('sha256', $capabilityToken);
    }

    /**
     * @return array{backend_pid: int, transaction_id: string, capability_token: string}
     */
    private function gcA2CapabilityProof(): array
    {
        try {
            $row = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id, '
                . 'current_setting(?, true) AS capability_token',
                [self::GC_A2_CAPABILITY_SETTING]
            );
        } catch (Throwable $exception) {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION, 0, $exception);
        }

        $backendPid = (int) ($row->backend_pid ?? 0);
        $transactionId = trim((string) ($row->transaction_id ?? ''));
        $capabilityToken = trim((string) ($row->capability_token ?? ''));

        if ($backendPid < 1 || $transactionId === '' || $capabilityToken === '') {
            throw new DomainException(self::ERROR_INVALID_TERMINAL_OBLIGATION_ATTESTATION);
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
     * @return \WeakMap<GeneralCashierCheckoutTerminalObligationAttestation, array<string, mixed>>
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
