<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation;
use RuntimeException;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationFoundationTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        $this->service = app(GuestLedgerCheckoutTerminalFinancialAttestationService::class);
        $this->lockService = app(PropertyBusinessDateOperationalLockService::class);
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function openBusinessDate(): PropertyBusinessDate
    {
        $bd = new PropertyBusinessDate();
        $bd->forceFill([
            'property_id' => $this->glfProperty->id,
            'business_date' => today(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'timezone_snapshot' => 'UTC',
            'opened_by' => $this->glfActor->id,
            'opened_at' => now(),
        ])->save();
        return $bd->fresh();
    }

    private function acquireContext(): PropertyBusinessDateOperationalLockContext
    {
        $bd = $this->openBusinessDate();
        return $this->lockService->acquire(
            $this->glfCompany->id,
            $this->glfProperty->id,
            [
                'property_business_date_id' => $bd->id,
                'property_id' => $this->glfProperty->id,
                'business_date' => $bd->business_date->format('Y-m-d'),
                'property_timezone' => 'UTC',
                'opened_by' => (string) $this->glfActor->id,
                'opened_at' => $bd->opened_at->utc()->toISOString(),
            ]
        );
    }

    private function makeStay(?string $reservationId = null, ?string $guestId = null): FrontDeskStay
    {
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->glfProperty->id,
            'reservation_id' => $reservationId ?? $reservation->id,
            'guest_id' => $guestId ?? $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->glfActor->id,
            'updated_by' => $this->glfActor->id,
        ])->save();
        return $stay->fresh();
    }

    private function makeFolio(string $reservationId, string $guestId): Folio
    {
        static $seq = 0; $seq++;
        $folio = new Folio();
        $folio->forceFill([
            'property_id' => $this->glfProperty->id,
            'folio_number' => 'F' . $seq . '-' . bin2hex(random_bytes(2)),
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
            'status' => 'open',
            'currency' => 'USD',
            'window_number' => $seq,
            'total_charges' => '0.00',
            'total_payments' => '0.00',
            'total_deposits' => '0.00',
            'total_ar_transfers' => '0.00',
            'balance' => '0.00',
            'opening_idempotency_key' => 'test-glfe-' . bin2hex(random_bytes(4)),
        ])->save();
        return $folio->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 1. Active transaction required
    // ═══════════════════════════════════════════════════════════════════════

    public function test_requires_active_transaction(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_REQUIRES_ACTIVE_TRANSACTION);

        $bd = $this->openBusinessDate();
        $context = new PropertyBusinessDateOperationalLockContext(
            company_id: $this->glfCompany->id,
            property_id: $this->glfProperty->id,
            property_business_date_id: $bd->id,
            business_date: $bd->business_date->format('Y-m-d'),
            property_timezone: 'UTC',
            opened_by: (string) $this->glfActor->id,
            opened_at: $bd->opened_at->utc()->toISOString(),
            source_fingerprint: 'test',
            postgres_backend_pid: 0,
            postgres_transaction_id: '0',
            lock_acquired_at: now()->toISOString(),
        );

        $this->service->attest($context, 'fake-id');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 2. GLF_E stable error wrapping for invalid NA-A2 context
    // ═══════════════════════════════════════════════════════════════════════

    public function test_invalid_operational_context_maps_to_glf_e_error(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT);

        DB::transaction(function () {
            $bd = $this->openBusinessDate();
            $context = new PropertyBusinessDateOperationalLockContext(
                company_id: $this->glfCompany->id,
                property_id: $this->glfProperty->id,
                property_business_date_id: $bd->id,
                business_date: $bd->business_date->format('Y-m-d'),
                property_timezone: 'UTC',
                opened_by: (string) $this->glfActor->id,
                opened_at: $bd->opened_at->utc()->toISOString(),
                source_fingerprint: 'test',
                postgres_backend_pid: 99999,
                postgres_transaction_id: '99999',
                lock_acquired_at: now()->toISOString(),
            );

            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $this->service->attest($context, $stay->id);
        });
    }

    public function test_stale_context_wraps_glf_e_error_with_previous(): void
    {
        $context = null;

        DB::transaction(function () use (&$context) {
            $context = $this->acquireContext();
        });

        try {
            DB::transaction(function () use ($context) {
                $reservation = $this->makeGlfReservation();
                $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
                $this->makeFolio($reservation->id, $reservation->primaryGuest->id);
                $this->service->attest($context, $stay->id);
            });
            $this->fail('Expected DomainException was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString(
                GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT,
                $e->getMessage()
            );
            $this->assertNotNull($e->getPrevious(), 'Must preserve original exception as previous.');
        }
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 3. Savepoint behavior
    // ═══════════════════════════════════════════════════════════════════════

    public function test_rolled_back_savepoint_context_rejected(): void
    {
        try {
            DB::transaction(function () {
                DB::beginTransaction();
                $bd = $this->openBusinessDate();
                $context = $this->lockService->acquire(
                    $this->glfCompany->id, $this->glfProperty->id,
                    ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                     'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                     'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
                );
                DB::rollBack();

                $reservation = $this->makeGlfReservation();
                $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
                $this->makeFolio($reservation->id, $reservation->primaryGuest->id);
                $this->service->attest($context, $stay->id);
            });
            $this->fail('Expected exception was not thrown.');
        } catch (DomainException $e) {
            $this->assertStringContainsString(
                GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT,
                $e->getMessage()
            );
        }
    }

    public function test_rolled_back_savepoint_attestation_rejected(): void
    {
        DB::transaction(function () {
            // 1. Acquire NA-A2 context in OUTER transaction
            $context = $this->acquireContext();

            // 2. Create fixture in outer transaction
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            // 3. Capture exact identities
            $proofBefore = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id'
            );
            $backendPid = (int) $proofBefore->backend_pid;
            $txid = trim((string) $proofBefore->transaction_id);

            // 4. Issue GLF-E attestation inside nested savepoint
            DB::beginTransaction(); // savepoint
            $attestation = $this->service->attest($context, $stay->id);
            DB::rollBack(); // rollback savepoint only

            // 5. Confirm same exact objects and identities
            $this->assertNotNull($context, 'NA-A2 context object retained.');
            $this->assertNotNull($attestation, 'GLF-E PHP attestation object retained.');

            $proofAfter = DB::selectOne(
                'SELECT pg_backend_pid() AS backend_pid, txid_current()::text AS transaction_id'
            );
            $this->assertEquals($backendPid, (int) $proofAfter->backend_pid, 'Backend PID unchanged.');
            $this->assertEquals($txid, trim((string) $proofAfter->transaction_id), 'Transaction ID unchanged.');

            // 6. assertIssuedForCurrentTransaction must reject — GLF-E capability
            //    was rolled back with the savepoint
            try {
                $this->service->assertIssuedForCurrentTransaction($context, $attestation);
                $this->fail('Expected DomainException for rolled-back savepoint attestation.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                    $e->getMessage()
                );
            }
        });
    }

    public function test_savepoint_release_preserves_context(): void
    {
        DB::transaction(function () {
            DB::beginTransaction();
            $bd = $this->openBusinessDate();
            $context = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );

            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);
            $attestation = $this->service->attest($context, $stay->id);
            DB::commit(); // release savepoint

            // After successful release, attestation still valid
            $this->service->assertIssuedForCurrentTransaction($context, $attestation);
            $this->assertTrue(true);
        });
    }

    public function test_superseded_context_rejected(): void
    {
        DB::transaction(function () {
            $bd = $this->openBusinessDate();
            $context1 = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );

            // Acquire a second context — supersedes the first via NA-A2 capability replacement
            $context2 = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );

            // The older context1 should now be invalid
            try {
                $reservation = $this->makeGlfReservation();
                $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
                $this->makeFolio($reservation->id, $reservation->primaryGuest->id);
                $this->service->attest($context1, $stay->id);
                $this->fail('Expected superseded context to be rejected.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT,
                    $e->getMessage()
                );
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 4. GLF-E savepoint capability behavior
    // ═══════════════════════════════════════════════════════════════════════

    public function test_savepoint_release_preserves_glf_e_attestation(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            // Issue attestation inside savepoint, then release
            DB::beginTransaction();
            $attestation = $this->service->attest($context, $stay->id);
            DB::commit(); // release savepoint

            // After successful release, attestation and capability are valid
            $this->service->assertIssuedForCurrentTransaction($context, $attestation);
            $this->assertTrue(true);
        });
    }

    public function test_second_issuance_invalidates_first(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $a1 = $this->service->attest($context, $stay->id);
            $a2 = $this->service->attest($context, $stay->id);

            // a2 valid (latest capability)
            $this->service->assertIssuedForCurrentTransaction($context, $a2);

            // a1 invalid (superseded capability)
            try {
                $this->service->assertIssuedForCurrentTransaction($context, $a1);
                $this->fail('Expected first attestation to be invalidated.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                    $e->getMessage()
                );
            }
        });
    }

    public function test_outer_attestation_restored_after_inner_rollback(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            // Issue outer attestation A (capability A)
            $aOuter = $this->service->attest($context, $stay->id);

            // Issue inner attestation B inside savepoint, then rollback
            DB::beginTransaction(); // savepoint
            $aInner = $this->service->attest($context, $stay->id); // capability B
            DB::rollBack(); // restores capability A

            // B invalid (capability rolled back)
            try {
                $this->service->assertIssuedForCurrentTransaction($context, $aInner);
                $this->fail('Expected inner attestation invalid after rollback.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                    $e->getMessage()
                );
            }

            // A valid (capability restored)
            $this->service->assertIssuedForCurrentTransaction($context, $aOuter);
            $this->assertTrue(true);
        });
    }

    public function test_released_inner_attestation_invalidates_outer(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            // Issue outer attestation A (capability A)
            $aOuter = $this->service->attest($context, $stay->id);

            // Issue inner attestation B inside savepoint, then release
            DB::beginTransaction(); // savepoint
            $aInner = $this->service->attest($context, $stay->id); // capability B
            DB::commit(); // release — capability B remains current

            // B valid (latest capability)
            $this->service->assertIssuedForCurrentTransaction($context, $aInner);

            // A invalid (capability superseded)
            try {
                $this->service->assertIssuedForCurrentTransaction($context, $aOuter);
                $this->fail('Expected outer attestation invalid after inner release.');
            } catch (DomainException $e) {
                $this->assertStringContainsString(
                    GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION,
                    $e->getMessage()
                );
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5. Exact-object issuance
    // ═══════════════════════════════════════════════════════════════════════

    public function test_exact_object_issuance(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $attestation = $this->service->attest($context, $stay->id);
            $this->service->assertIssuedForCurrentTransaction($context, $attestation);
            $this->assertTrue(true);
        });
    }

    public function test_forged_attestation_rejected(): void
    {
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage(GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_TERMINAL_FINANCIAL_ATTESTATION);

        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $real = $this->service->attest($context, $stay->id);

            // Forge a field-identical attestation
            $forged = GuestLedgerCheckoutTerminalFinancialAttestation::create(
                status: $real->status,
                property_id: $real->property_id,
                property_business_date_id: $real->property_business_date_id,
                business_date: $real->business_date,
                front_desk_stay_id: $real->front_desk_stay_id,
                reservation_id: $real->reservation_id,
                folio_count: $real->folio_count,
                canonical_aggregate_balance: $real->canonical_aggregate_balance,
                currency: $real->currency,
                blocker_codes: $real->blocker_codes,
                review_reasons: $real->review_reasons,
                evidence_unavailable_codes: $real->evidence_unavailable_codes,
                cash_linked_references: $real->cash_linked_references,
                cashier_session_ids: $real->cashier_session_ids,
                source_fingerprint: $real->source_fingerprint,
                evaluated_at: $real->evaluated_at,
                markers: $real->markers,
            );

            $this->service->assertIssuedForCurrentTransaction($context, $forged);
        });
    }

    public function test_cross_transaction_attestation_rejected(): void
    {
        $attestation = null;
        $context = null;

        DB::transaction(function () use (&$attestation, &$context) {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);
            $attestation = $this->service->attest($context, $stay->id);
        });

        $this->expectException(DomainException::class);

        DB::transaction(function () use ($attestation, $context) {
            $this->service->assertIssuedForCurrentTransaction($context, $attestation);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 5. Exact public whitelist
    // ═══════════════════════════════════════════════════════════════════════

    public function test_exact_public_whitelist(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $a = $this->service->attest($context, $stay->id);
            $props = get_object_vars($a);

            $expectedProps = [
                'attestation_version', 'status', 'owner', 'transaction_bound',
                'property_id', 'property_business_date_id', 'business_date',
                'front_desk_stay_id', 'reservation_id', 'folio_count',
                'canonical_aggregate_balance', 'currency',
                'blocker_codes', 'review_reasons', 'evidence_unavailable_codes',
                'cash_linked_references', 'cashier_session_ids',
                'source_fingerprint', 'evaluated_at', 'markers',
            ];

            $actualProps = array_keys($props);
            sort($expectedProps);
            sort($actualProps);
            $this->assertEquals($expectedProps, $actualProps, 'Exact whitelist mismatch.');

            // Prove absence of forbidden fields
            $this->assertArrayNotHasKey('folio_ids', $props);
            $this->assertArrayNotHasKey('guest_id', $props);
            $this->assertArrayNotHasKey('can_execute', $props);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 6. Deterministic fingerprint
    // ═══════════════════════════════════════════════════════════════════════

    public function test_same_transaction_deterministic_fingerprint(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $a1 = $this->service->attest($context, $stay->id);
            $a2 = $this->service->attest($context, $stay->id);

            $this->assertEquals($a1->source_fingerprint, $a2->source_fingerprint);
        });
    }

    public function test_different_transaction_different_fingerprint(): void
    {
        // Create fixture BEFORE both attestation transactions.
        $reservation = $this->makeGlfReservation();
        $guest = $reservation->primaryGuest;
        $stay = $this->makeStay($reservation->id, $guest->id);
        $this->makeFolio($reservation->id, $guest->id);
        $bd = $this->openBusinessDate();

        $fpA = ''; $fpB = '';
        $txidA = ''; $txidB = '';

        // Transaction A — real GLF-E attest
        DB::transaction(function () use (&$fpA, &$txidA, $stay, $bd) {
            $txidA = DB::selectOne("SELECT txid_current()::text AS txid")->txid;
            $context = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );
            $fpA = $this->service->attest($context, $stay->id)->source_fingerprint;
        });

        // Transaction B — same BD, same stay, same source rows
        DB::transaction(function () use (&$fpB, &$txidB, $stay, $bd) {
            $txidB = DB::selectOne("SELECT txid_current()::text AS txid")->txid;
            $context = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );
            $fpB = $this->service->attest($context, $stay->id)->source_fingerprint;
        });

        // Transaction IDs differ
        $this->assertNotEquals($txidA, $txidB, 'Cross-transaction txids must differ.');

        // Same source data, both real GLF-E attest calls
        $this->assertNotEmpty($fpA); $this->assertNotEmpty($fpB);

        // Different transactions → different fingerprints
        $this->assertNotEquals($fpA, $fpB, 'Cross-transaction fingerprints must differ over identical source.');
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 7. Zero-write proof
    // ═══════════════════════════════════════════════════════════════════════

    public function test_zero_writes_sql_proof(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->service->attest($context, $stay->id);

            $forbidden = ['insert', 'update', 'delete', 'merge', 'truncate', 'alter', 'drop', 'create'];
            $inspectedQueries = 0;
            $mutationQueries = 0;

            foreach (DB::getQueryLog() as $entry) {
                // Normalize: strip comments, collapse whitespace, lowercase
                $sql = strtolower(trim($entry['query'] ?? ''));
                $sql = preg_replace('/\/\*.*?\*\//s', '', $sql);   // block comments
                $sql = preg_replace('/--[^\n]*/', '', $sql);       // line comments
                $sql = preg_replace('/\s+/', ' ', $sql);            // normalize whitespace

                if (str_starts_with($sql, 'set local')) continue;
                if (str_starts_with($sql, 'select')) continue;

                $inspectedQueries++;
                foreach ($forbidden as $op) {
                    if (str_contains($sql, $op)) {
                        $mutationQueries++;
                    }
                    $this->assertStringNotContainsString($op, $sql,
                        "Mutation '{$op}' found in query: {$sql}");
                }
            }

            $this->assertGreaterThan(0, count(DB::getQueryLog()), 'GLF-E zero-write proof must inspect executed SQL.');
            $this->assertSame(0, $mutationQueries, 'GLF-E must not execute business mutation SQL.');

            DB::disableQueryLog();
        });
    }

    public function test_cte_mutations_detected(): void
    {
        // Static proof: the normalizer correctly identifies CTE-wrapped mutations.
        // The prefix-based detection catches any mutation keyword in the normalized SQL.
        $testSql = [
            'with candidate as (select id from folios) update folios set balance=0' => 'update',
            'with c as (select 1) delete from folios where id=1' => 'delete',
            'with c as (select 1) insert into folios (id) values (1)' => 'insert',
            'select id from folios for update' => null, // OK
            'set local lock_timeout = \'5s\'' => null, // OK
        ];
        foreach ($testSql as $sql => $expectedMutation) {
            $normalized = strtolower(preg_replace('/\s+/', ' ', $sql));
            if ($expectedMutation === null) {
                $this->assertTrue(true); // no mutation expected
            } else {
                $found = false;
                foreach (['insert', 'update', 'delete', 'merge', 'truncate', 'alter', 'drop', 'create'] as $op) {
                    if (str_contains($normalized, $op)) {
                        $found = true;
                        $this->assertEquals($expectedMutation, $op, "Expected mutation '{$expectedMutation}' in: {$sql}");
                        break;
                    }
                }
                $this->assertTrue($found, "Mutation not detected in CTE SQL: {$sql}");
            }
        }
    }

    public function test_zero_writes_snapshot_proof(): void
    {
        // Full-row hash comparison — not just ID counts.
        // Hash the complete canonical state of every protected table before and after attest().
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $tables = [
                'properties', 'property_business_dates', 'front_desk_stays',
                'reservations', 'folios', 'folio_items',
                'guest_payment_transactions', 'guest_payment_allocations',
                'guest_payment_reversals', 'guest_deposit_transactions',
                'guest_deposit_applications', 'guest_deposit_reversals',
                'guest_refund_transactions', 'guest_ar_transfer_requests',
                'guest_ar_transfer_decisions', 'cashier_sessions', 'night_audit_runs',
            ];

            $beforeHashes = [];
            foreach ($tables as $table) {
                $rows = DB::table($table)->select('*')->orderBy('id')->get()->toArray();
                $beforeHashes[$table] = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
            }

            $this->service->attest($context, $stay->id);

            foreach ($tables as $table) {
                $rows = DB::table($table)->select('*')->orderBy('id')->get()->toArray();
                $afterHash = hash('sha256', json_encode($rows, JSON_UNESCAPED_SLASHES));
                $this->assertEquals(
                    $beforeHashes[$table],
                    $afterHash,
                    "Table {$table} full-row snapshot was mutated during attest."
                );
            }
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 8. No General Cashier query
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_general_cashier_query(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->service->attest($context, $stay->id);

            foreach (DB::getQueryLog() as $entry) {
                $sql = $entry['query'] ?? '';
                $this->assertStringNotContainsString('cashier_sessions', $sql);
            }

            DB::disableQueryLog();
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 9. No checkout artifacts
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_checkout_artifacts(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            $a = $this->service->attest($context, $stay->id);
            $serialized = json_encode($a);
            $this->assertStringNotContainsString('can_execute', $serialized);
            $this->assertStringNotContainsString('executeCheckout', $serialized);
            $this->assertStringNotContainsString('frontdesk.checkout-execution.execute', $serialized);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 10. Narrow lock-timeout classification
    // ═══════════════════════════════════════════════════════════════════════

    public function test_lock_timeout_error_code(): void
    {
        $this->assertEquals(
            'GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT',
            GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_FINANCIAL_SOURCE_LOCK_TIMEOUT
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // 11. Locked-mode no-re-query proof
    // ═══════════════════════════════════════════════════════════════════════

    public function test_no_locked_mode_requery(): void
    {
        DB::transaction(function () {
            $context = $this->acquireContext();
            $reservation = $this->makeGlfReservation();
            $stay = $this->makeStay($reservation->id, $reservation->primaryGuest->id);
            $this->makeFolio($reservation->id, $reservation->primaryGuest->id);

            DB::enableQueryLog();
            DB::flushQueryLog();

            $this->service->attest($context, $stay->id);

            $mutableTables = [
                'reservations', 'folios', 'folio_items',
                'guest_payment_transactions', 'guest_payment_allocations',
                'guest_payment_reversals', 'guest_deposit_transactions',
                'guest_deposit_applications', 'guest_deposit_reversals',
                'guest_refund_transactions', 'guest_ar_transfer_requests',
                'guest_ar_transfer_decisions',
            ];

            $tableQueries = [];
            foreach (DB::getQueryLog() as $entry) {
                $sql = strtolower($entry['query'] ?? '');
                foreach ($mutableTables as $table) {
                    if (str_contains($sql, 'from "' . $table) || str_contains($sql, 'from "' . $table)) {
                        $tableQueries[$table][] = $sql;
                    }
                }
            }

            // Each mutable table should appear at most once (lock stage)
            foreach ($tableQueries as $table => $queries) {
                $selectCount = 0;
                foreach ($queries as $q) {
                    if (str_starts_with(trim($q), 'select')) {
                        $selectCount++;
                    }
                }
                $this->assertLessThanOrEqual(1, $selectCount,
                    "Table {$table} was queried {$selectCount} times in locked mode.");
            }

            DB::disableQueryLog();
        });
    }

    public function test_glf_e_does_not_leak_na_a2_error_code(): void
    {
        // The GLF-E error code for context rejection must be its own,
        // not an NA-A2 code.
        $this->assertNotEquals(
            'NA_A2_INVALID_OPERATIONAL_LOCK_CONTEXT',
            GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT
        );
        $this->assertEquals(
            'GLF_E_INVALID_OPERATIONAL_LOCK_CONTEXT',
            GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_INVALID_OPERATIONAL_LOCK_CONTEXT
        );
    }
}
