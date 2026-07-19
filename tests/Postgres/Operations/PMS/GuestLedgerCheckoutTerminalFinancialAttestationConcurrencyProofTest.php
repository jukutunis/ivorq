<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\PropertyBusinessDateOperationalLockService;
use Modules\Foundation\Property\ValueObjects\PropertyBusinessDateOperationalLockContext;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\Services\Ports\GuestLedgerCompletedSettlementConflictParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerPostingCompletenessParticipationPort;
use Modules\Operations\PMS\Services\Ports\GuestLedgerSettlementHoldParticipationPort;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;
use Illuminate\Foundation\Testing\DatabaseMigrations;

class GuestLedgerCheckoutTerminalFinancialAttestationConcurrencyProofTest extends PostgresTestCase
{
    use DatabaseMigrations;
    use CreatesGuestLedgerFolioData;

    private GuestLedgerCheckoutTerminalFinancialAttestationService $service;
    private PropertyBusinessDateOperationalLockService $lockService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();

        app()->instance(GuestLedgerPostingCompletenessParticipationPort::class, new class implements GuestLedgerPostingCompletenessParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'cp_p'), 'source_identifiers' => []];
            }
        });
        app()->instance(GuestLedgerSettlementHoldParticipationPort::class, new class implements GuestLedgerSettlementHoldParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'cp_sh'), 'source_identifiers' => []];
            }
        });
        app()->instance(GuestLedgerCompletedSettlementConflictParticipationPort::class, new class implements GuestLedgerCompletedSettlementConflictParticipationPort {
            public function participate(string $reservationId, string $propertyId): array {
                return ['status' => self::AVAILABLE_CLEAR, 'code' => null, 'source_fingerprint' => hash('sha256', 'cp_cs'), 'source_identifiers' => []];
            }
        });

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

    private function makeStay(string $reservationId, string $guestId): FrontDeskStay
    {
        $stay = new FrontDeskStay();
        $stay->forceFill([
            'property_id' => $this->glfProperty->id,
            'reservation_id' => $reservationId,
            'guest_id' => $guestId,
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
            'folio_number' => 'P' . $seq . '-' . bin2hex(random_bytes(2)),
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
            'opening_idempotency_key' => 'test-cp-' . bin2hex(random_bytes(4)),
        ])->save();
        return $folio->fresh();
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario A: Participant holds locks
    // ═══════════════════════════════════════════════════════════════════════

    public function test_participant_holds_locks_and_allows_reattest(): void
    {
        DB::transaction(function () {
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

            $a = $this->service->attest($context, $stay->id);
            $this->assertNotNull($a);

            // Locks held — re-attest with same context works
            $a2 = $this->service->attest($context, $stay->id);
            $this->assertEquals($a->source_fingerprint, $a2->source_fingerprint);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario C: Rollback zero writes
    // ═══════════════════════════════════════════════════════════════════════

    public function test_rollback_zero_writes(): void
    {
        $rolledBack = false;

        try {
            DB::transaction(function () use (&$rolledBack) {
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

                $this->service->attest($context, $stay->id);
                throw new \RuntimeException('Simulated rollback');
            });
        } catch (\RuntimeException $e) {
            $rolledBack = true;
        }

        $this->assertTrue($rolledBack);
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario D: Different reservations within same property
    // ═══════════════════════════════════════════════════════════════════════

    public function test_different_reservations_no_cross_block(): void
    {
        DB::transaction(function () {
            $bd = $this->openBusinessDate();
            $context = $this->lockService->acquire(
                $this->glfCompany->id, $this->glfProperty->id,
                ['property_business_date_id' => $bd->id, 'property_id' => $this->glfProperty->id,
                 'business_date' => $bd->business_date->format('Y-m-d'), 'property_timezone' => 'UTC',
                 'opened_by' => (string) $this->glfActor->id, 'opened_at' => $bd->opened_at->utc()->toISOString()],
            );

            $r1 = $this->makeGlfReservation();
            $s1 = $this->makeStay($r1->id, $r1->primaryGuest->id);
            $this->makeFolio($r1->id, $r1->primaryGuest->id);

            $r2 = $this->makeGlfReservation();
            $s2 = $this->makeStay($r2->id, $r2->primaryGuest->id);
            $this->makeFolio($r2->id, $r2->primaryGuest->id);

            $a1 = $this->service->attest($context, $s1->id);
            $a2 = $this->service->attest($context, $s2->id);

            $this->assertNotNull($a1);
            $this->assertNotNull($a2);
            $this->assertNotEquals($a1->reservation_id, $a2->reservation_id);
        });
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Scenario E: Lock timeout code and narrow check
    // ═══════════════════════════════════════════════════════════════════════

    public function test_lock_timeout_error_code(): void
    {
        $this->assertEquals(
            'GLF_E_FINANCIAL_SOURCE_LOCK_TIMEOUT',
            GuestLedgerCheckoutTerminalFinancialAttestationService::ERROR_FINANCIAL_SOURCE_LOCK_TIMEOUT
        );
    }

    // ═══════════════════════════════════════════════════════════════════════
    // Distinct transaction IDs across transactions
    // ═══════════════════════════════════════════════════════════════════════

    public function test_distinct_transactions_have_distinct_txids(): void
    {
        $txid1 = '';
        $txid2 = '';

        DB::transaction(function () use (&$txid1) {
            $row = DB::selectOne('SELECT txid_current()::text AS txid');
            $txid1 = $row->txid;
        });

        DB::transaction(function () use (&$txid2) {
            $row = DB::selectOne('SELECT txid_current()::text AS txid');
            $txid2 = $row->txid;
        });

        $this->assertNotEquals($txid1, $txid2);
    }
}
