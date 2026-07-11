<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Services\GuestLedgerPaymentAllocationEffectService;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GuestPaymentConcurrencyProofTest extends PostgresTestCase
{
    use CreatesGuestLedgerFolioData;
    use RefreshDatabase;

    private GuestPaymentLifecycleService $payments;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();
        $this->actingAs($this->glfActor)
            ->withSession([
                'active_property_id' => $this->glfProperty->id,
                'current_property_id' => $this->glfProperty->id,
                'active_company_id' => $this->glfCompany->id,
            ]);
        app(CurrentPropertyService::class)->setPropertyId($this->glfProperty->id);

        foreach ([
            GuestPaymentLifecycleService::RECORD_PERMISSION,
            GuestPaymentLifecycleService::ALLOCATE_PERMISSION,
            GuestPaymentLifecycleService::VOID_PERMISSION,
            GuestPaymentLifecycleService::REVERSAL_PERMISSION,
        ] as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->glfActor->givePermissionTo([
            GuestPaymentLifecycleService::RECORD_PERMISSION,
            GuestPaymentLifecycleService::ALLOCATE_PERMISSION,
            GuestPaymentLifecycleService::VOID_PERMISSION,
            GuestPaymentLifecycleService::REVERSAL_PERMISSION,
        ]);

        $this->payments = app(GuestPaymentLifecycleService::class);
    }

    public function test_recording_and_allocation_replay_have_single_source_effect(): void
    {
        $pid = getmypid();
        $backendPid = (int) DB::selectOne('select pg_backend_pid() as pid')->pid;
        $this->assertGreaterThan(0, $pid);
        $this->assertGreaterThan(0, $backendPid);

        [$payment, $folio] = $this->paymentAndFolio('75.00');
        $again = $this->payments->recordCashPayment($this->glfActor, $payment->reservation_id, $payment->cashier_session_id, '75.00', 'record-' . $payment->reservation_id . '-75.00');
        $this->assertSame($payment->id, $again->id);

        $a1 = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '75.00', 'alloc-replay');
        $a2 = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '75.00', 'alloc-replay');

        $this->assertSame($a1->id, $a2->id);
        $this->assertSame(1, GuestPaymentAllocation::count());
        $this->assertSame(1, FolioItem::where('source_type', GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION)->count());
        $this->assertSame('75.00', $folio->fresh()->total_payments);
        $this->assertSame(GuestPaymentLifecycleStatusEnum::FullyAllocated, $payment->fresh()->lifecycle_status);
    }

    public function test_over_allocation_and_valid_split_allocation_have_bounded_final_totals(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('100.00');
        $secondFolio = $this->makeGlfFolio($payment->reservation, $payment->reservation->primaryGuest);

        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '60.00', 'alloc-split-a');

        try {
            $this->payments->allocatePayment($this->glfActor, $payment->id, $secondFolio->id, '50.00', 'alloc-over');
            $this->fail('Over-allocation must fail controlled.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('GUEST_PAYMENT_OVER_ALLOCATION', $exception->getMessage());
        }

        $this->payments->allocatePayment($this->glfActor, $payment->id, $secondFolio->id, '40.00', 'alloc-split-b');

        $this->assertSame('100.00', $this->activeAllocationTotal($payment->id));
        $this->assertSame(2, GuestPaymentAllocation::count());
        $this->assertSame(2, FolioItem::where('source_type', GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION)->count());
        $this->assertSame(GuestPaymentLifecycleStatusEnum::FullyAllocated, $payment->fresh()->lifecycle_status);
    }

    public function test_double_reversal_and_reallocation_consistency(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('90.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '90.00', 'alloc-race');

        $this->confirm(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $r1 = $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'RACE_REPLAY', 'reverse-race');
        $r2 = $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'RACE_REPLAY', 'reverse-race');

        $this->assertSame($r1->id, $r2->id);
        $this->assertSame(1, GuestPaymentReversal::count());
        $this->assertSame(1, FolioItem::where('source_type', GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION_REVERSAL)->count());
        $this->assertSame('0.00', $this->activeAllocationTotal($payment->id));
        $this->assertSame(GuestPaymentLifecycleStatusEnum::Recorded, $payment->fresh()->lifecycle_status);

        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '90.00', 'alloc-after-race');
        $this->assertSame('90.00', $this->activeAllocationTotal($payment->id));
        $this->assertSame(GuestPaymentLifecycleStatusEnum::FullyAllocated, $payment->fresh()->lifecycle_status);
    }

    private function paymentAndFolio(string $amount): array
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->makeGlfFolio($reservation, $reservation->primaryGuest);
        $payment = $this->payments->recordCashPayment(
            $this->glfActor,
            $reservation->id,
            $this->openCashierSession()->id,
            $amount,
            'record-' . $reservation->id . '-' . $amount
        );

        return [$payment, $folio];
    }

    private function openCashierSession(): CashierSession
    {
        $session = new CashierSession();
        $session->forceFill([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
        ])->save();

        return $session->fresh();
    }

    private function confirm(string $intent): void
    {
        app(SensitiveActionConfirmationService::class)->confirm(
            $this->glfActor,
            $intent,
            'password',
            $this->glfCompany->id,
            $this->glfProperty->id
        );
    }

    private function activeAllocationTotal(string $paymentId): string
    {
        $total = '0.00';
        foreach (GuestPaymentAllocation::where('guest_payment_transaction_id', $paymentId)->get() as $allocation) {
            if (!GuestPaymentReversal::where('guest_payment_allocation_id', $allocation->id)->exists()) {
                $total = bcadd($total, (string) $allocation->amount, 2);
            }
        }

        return $total;
    }
}
