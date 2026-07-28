<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Exception;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\CheckoutSensitiveConfirmationConsumption;
use Modules\Foundation\Authorization\Services\CheckoutSensitiveConfirmationService;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutFinalReviewStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecutionService;
use Modules\Operations\GeneralCashier\Enums\GeneralCashierCheckoutTerminalObligationAttestationStatusEnum;
use Modules\Operations\GeneralCashier\Services\GeneralCashierCheckoutTerminalObligationAttestationService;
use Modules\Operations\GeneralCashier\ValueObjects\GeneralCashierCheckoutTerminalObligationAttestation;
use Modules\Operations\NightAudit\Services\NightAuditCheckoutConcurrencyGuardService;
use Modules\Operations\NightAudit\ValueObjects\NightAuditCheckoutConcurrencyAttestation;
use Modules\Operations\PMS\Enums\GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum;
use Modules\Operations\PMS\Services\GuestLedgerCheckoutTerminalFinancialAttestationService;
use Modules\Operations\PMS\ValueObjects\GuestLedgerCheckoutTerminalFinancialAttestation;
use Tests\Postgres\Operations\FrontDesk\Concerns\CreatesFrontDeskFdA2Data;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionRollbackTest extends PostgresTestCase
{
    use CreatesFrontDeskFdA2Data;
    use \Illuminate\Foundation\Testing\RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpFrontDeskFdA2Fixture();
        
        \Modules\Foundation\Authorization\Models\Permission::firstOrCreate(['name' => \Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION, 'guard_name' => 'web']);
        $this->frontDeskActor->givePermissionTo(\Modules\Operations\FrontDesk\Services\FrontDeskCheckoutExecuteAuthorizationService::EXECUTE_PERMISSION);
        $this->actingAs($this->frontDeskActor, 'web');
        session($this->propertySession($this->property));
    }

    public function test_valid_checkout_completes_full_lifecycle(): void
    {
        [$stay] = $this->setupValidCheckoutState('101', 'idemp-lifecycle');

        $service = app(FrontDeskCheckoutExecutionService::class);
        $result = $service->execute($this->frontDeskActor, $stay->id, 'idemp-lifecycle');

        $this->assertFalse($result->replayed);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut->value, $result->terminalStatus);

        $stay->refresh();
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $stay->status);

        $this->assertSame(1, FrontDeskCheckoutExecution::count());
        $this->assertSame(1, FrontDeskCheckoutHousekeepingHandoff::count());
        $this->assertSame(1, CheckoutSensitiveConfirmationConsumption::count());

        $audit = DB::table('audit_logs')
            ->where('event', 'front_desk_checkout_completed')
            ->where('user_id', $this->frontDeskActor->id)
            ->first();

        $this->assertNotNull($audit);
    }

    public function test_fault_after_claim_rolls_back_everything(): void
    {
        [$stay] = $this->setupValidCheckoutState('102', 'idemp-fault-claim');

        CheckoutSensitiveConfirmationConsumption::created(function () {
            throw new Exception('Fault after claim');
        });

        $this->assertFaultRollsBack($stay->id, 'idemp-fault-claim', 'Fault after claim');
    }

    public function test_fault_after_execution_insert_rolls_back_everything(): void
    {
        [$stay] = $this->setupValidCheckoutState('103', 'idemp-fault-exec');

        FrontDeskCheckoutExecution::created(function () {
            throw new Exception('Fault after execution insert');
        });

        $this->assertFaultRollsBack($stay->id, 'idemp-fault-exec', 'Fault after execution insert');
    }

    public function test_fault_after_stay_transition_rolls_back_everything(): void
    {
        [$stay] = $this->setupValidCheckoutState('104', 'idemp-fault-stay');

        FrontDeskStay::updated(function (FrontDeskStay $model) {
            if ($model->status === FrontDeskStayStatusEnum::CheckedOut) {
                throw new Exception('Fault after stay transition');
            }
        });

        $this->assertFaultRollsBack($stay->id, 'idemp-fault-stay', 'Fault after stay transition');
    }

    public function test_fault_before_handoff_rolls_back_everything(): void
    {
        [$stay] = $this->setupValidCheckoutState('105', 'idemp-fault-handoff');

        FrontDeskCheckoutHousekeepingHandoff::creating(function () {
            throw new Exception('Fault before handoff');
        });

        $this->assertFaultRollsBack($stay->id, 'idemp-fault-handoff', 'Fault before handoff');
    }

    public function test_handoff_database_source_integrity_rejection_rolls_back(): void
    {
        [$stay] = $this->setupValidCheckoutState('106', 'idemp-fault-handoff-src');

        // Simulate the DB-level NOT NULL / FK violation by triggering a DB error
        // after execution insert but before handoff, via a save() override.
        // The handoff source-integrity guard verifies that a corrupted handoff
        // row cannot exist without a valid execution reference.
        FrontDeskCheckoutHousekeepingHandoff::saving(function (FrontDeskCheckoutHousekeepingHandoff $model) {
            // Setting checkout_execution_id to null would violate FK in real DB
            $model->checkout_execution_id = null;
        });

        // The resulting DB level FK violation should roll back the transaction
        $this->assertFaultRollsBack($stay->id, 'idemp-fault-handoff-src', null);
    }

    private function assertFaultRollsBack(string $stayId, string $idempotencyKey, ?string $expectedMessage): void
    {
        $service = app(FrontDeskCheckoutExecutionService::class);
        $exceptionThrown = false;

        try {
            $service->execute($this->frontDeskActor, $stayId, $idempotencyKey);
        } catch (\Throwable $exception) {
            if ($expectedMessage !== null) {
                $this->assertSame($expectedMessage, $exception->getMessage());
            }
            $exceptionThrown = true;
        }

        $this->assertTrue($exceptionThrown);

        $stay = FrontDeskStay::find($stayId);
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, $stay->status);

        $this->assertSame(0, FrontDeskCheckoutExecution::count());
        $this->assertSame(0, FrontDeskCheckoutHousekeepingHandoff::count());
        $this->assertSame(0, CheckoutSensitiveConfirmationConsumption::count());
    }

    private function setupValidCheckoutState(string $room, string $idempotencyKey): array
    {
        [$stay, $roomModel, $reservation] = $this->checkedInStay($room);

        $this->createValidAuthoritativeBusinessDate();

        $occurredAt = now();
        $status = FrontDeskDepartureCheckoutFinalReviewStatusEnum::CheckoutFinalReviewReady->value;
        $sourceHash = hash('sha256', implode('|', [$stay->id, $status, '', $occurredAt->toISOString()]));

        $finalReview = new FrontDeskDepartureCheckoutFinalReview();
        $finalReview->forceFill([
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $reservation,
            'guest_id' => $stay->guest_id,
            'room_id' => $stay->current_room_id,
            'final_review_status' => $status,
            'idempotency_key' => 'review-' . Str::ulid(),
            'source_hash' => $sourceHash,
            'occurred_at' => $occurredAt,
            'created_by' => $this->frontDeskActor->id,
            'created_at' => $occurredAt,
        ])->save();

        $this->mock(NightAuditCheckoutConcurrencyGuardService::class)
            ->shouldReceive('attest')
            ->andReturn(new NightAuditCheckoutConcurrencyAttestation(
                NightAuditCheckoutConcurrencyAttestation::VERSION,
                NightAuditCheckoutConcurrencyAttestation::STATUS_CLEAR,
                NightAuditCheckoutConcurrencyAttestation::OWNER,
                true,
                false,
                $this->property->id,
                'date-id',
                '2099-01-01',
                'UTC',
                hash('sha256', 'na'),
                now()->toISOString(),
                []
            ));

        $this->mock(GuestLedgerCheckoutTerminalFinancialAttestationService::class)
            ->shouldReceive('attest')
            ->andReturn(GuestLedgerCheckoutTerminalFinancialAttestation::create(
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady,
                $this->property->id,
                'date-id',
                '2099-01-01',
                $stay->id,
                $stay->reservation_id,
                0,
                '0.00',
                'USD',
                [],
                [],
                [],
                [],
                [],
                hash('sha256', 'fin'),
                now()->toISOString(),
                []
            ))
            ->shouldReceive('assertIssuedForCurrentTransaction');

        $this->mock(GeneralCashierCheckoutTerminalObligationAttestationService::class)
            ->shouldReceive('attest')
            ->andReturn(GeneralCashierCheckoutTerminalObligationAttestation::create(
                GeneralCashierCheckoutTerminalObligationAttestationStatusEnum::GeneralCashierTerminalObligationClear,
                $this->property->id,
                'date-id',
                '2099-01-01',
                $stay->id,
                $stay->reservation_id,
                GuestLedgerCheckoutTerminalFinancialAttestationStatusEnum::PmsTerminalFinancialReady->value,
                hash('sha256', 'fin'),
                [],
                0,
                [],
                [],
                [],
                hash('sha256', 'cash'),
                now()->toISOString(),
                []
            ))
            ->shouldReceive('assertIssuedForCurrentTransaction');

        $issuanceId = (string) Str::ulid();
        $identity = (string) Str::ulid();
        $sessionId = session()->getId();
        $sessionFingerprint = CheckoutSensitiveConfirmationService::fingerprintSession($sessionId);
        $confirmedAt = now();
        $expiresAt = now()->addMinutes(15);
        $fingerprint = hash('sha256', implode('|', [
            CheckoutSensitiveConfirmationService::INTENT,
            $identity,
            $this->frontDeskActor->id,
            $this->property->company_id,
            $this->property->id,
            $stay->id,
            $idempotencyKey,
            $sessionFingerprint,
            $confirmedAt->toISOString(),
            $expiresAt->toISOString(),
        ]));

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $issuanceId,
            'confirmation_identity' => $identity,
            'intent' => CheckoutSensitiveConfirmationService::INTENT,
            'actor_id' => $this->frontDeskActor->id,
            'company_id' => $this->property->company_id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => $sessionFingerprint,
            'confirmation_fingerprint' => $fingerprint,
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        session([
            CheckoutSensitiveConfirmationService::SESSION_KEY => [
                CheckoutSensitiveConfirmationService::INTENT => [
                    'actor_id' => $this->frontDeskActor->id,
                    'intent' => CheckoutSensitiveConfirmationService::INTENT,
                    'company_id' => $this->property->company_id,
                    'property_id' => $this->property->id,
                    'front_desk_stay_id' => $stay->id,
                    'checkout_idempotency_key' => $idempotencyKey,
                    'issuance_id' => $issuanceId,
                    'confirmation_identity' => $identity,
                    'confirmation_fingerprint' => $fingerprint,
                    'session_fingerprint' => $sessionFingerprint,
                    'confirmed_at' => $confirmedAt->toISOString(),
                    'expires_at' => $expiresAt->toISOString(),
                ],
            ],
        ]);

        return [$stay, $roomModel, $reservation];
    }
}
