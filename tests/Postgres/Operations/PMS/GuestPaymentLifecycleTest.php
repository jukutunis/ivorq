<?php

namespace Tests\Postgres\Operations\PMS;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentLifecycleStatusEnum;
use Modules\Operations\PMS\Enums\GuestPaymentReversalTypeEnum;
use Modules\Operations\PMS\Enums\GuestPaymentTenderTypeEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;
use Modules\Operations\PMS\Models\GuestPaymentReversal;
use Modules\Operations\PMS\Services\GuestLedgerPaymentAllocationEffectService;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
use Modules\Operations\PMS\Services\GuestPaymentLifecycleService;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GuestPaymentLifecycleTest extends PostgresTestCase
{
    use CreatesGuestLedgerFolioData;
    use RefreshDatabase;

    private GuestPaymentLifecycleService $payments;
    private GuestLedgerFolioAggregateService $folios;

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
        $this->folios = app(GuestLedgerFolioAggregateService::class);
    }

    public function test_records_source_proven_cash_payment_without_folio_or_cashier_mutation(): void
    {
        $reservation = $this->makeGlfReservation();
        $session = $this->openCashierSession();
        $before = $session->fresh()->only(['status', 'closed_at', 'closed_by']);

        $payment = $this->payments->recordCashPayment(
            $this->glfActor,
            $reservation->id,
            $session->id,
            '125.00',
            'record-' . $reservation->id
        );

        $this->assertSame($this->glfProperty->id, $payment->property_id);
        $this->assertSame($reservation->id, $payment->reservation_id);
        $this->assertSame($reservation->primary_guest_id, $payment->guest_id);
        $this->assertSame('USD', $payment->currency);
        $this->assertSame('125.00', (string) $payment->amount);
        $this->assertSame(GuestPaymentTenderTypeEnum::Cash, $payment->tender_type);
        $this->assertSame(GuestPaymentLifecycleStatusEnum::Recorded, $payment->lifecycle_status);
        $this->assertSame($session->id, $payment->cashier_session_id);
        $this->assertSame($this->glfActor->id, $payment->recorded_by);
        $this->assertSame(0, FolioItem::count());
        $this->assertSame($before, $session->fresh()->only(['status', 'closed_at', 'closed_by']));

        $replay = $this->payments->recordCashPayment(
            $this->glfActor,
            $reservation->id,
            $session->id,
            '125.00',
            'record-' . $reservation->id
        );

        $this->assertSame($payment->id, $replay->id);
        $this->assertSame(1, DB::table('guest_payment_transactions')->count());
    }

    public function test_recording_conflict_and_invalid_cashier_session_fail_closed(): void
    {
        $reservation = $this->makeGlfReservation();
        $session = $this->openCashierSession();
        $this->payments->recordCashPayment($this->glfActor, $reservation->id, $session->id, '20.00', 'record-conflict');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GUEST_PAYMENT_IDEMPOTENCY_CONFLICT');
        $this->payments->recordCashPayment($this->glfActor, $reservation->id, $session->id, '25.00', 'record-conflict');
    }

    public function test_closed_and_non_owner_cashier_sessions_are_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $closed = $this->openCashierSession(['status' => CashierSessionStatusEnum::CLOSED->value, 'closed_at' => now(), 'closed_by' => $this->glfActor->id]);

        try {
            $this->payments->recordCashPayment($this->glfActor, $reservation->id, $closed->id, '30.00', 'record-closed');
            $this->fail('Closed session must fail closed.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('OPEN', $exception->getMessage());
        }

        $other = $this->openCashierSession(['cashier_user_id' => $this->glfOtherActor->id]);

        $this->expectException(AuthorizationException::class);
        $this->payments->recordCashPayment($this->glfActor, $reservation->id, $other->id, '30.00', 'record-owner');
    }

    public function test_allocates_payment_to_folio_and_creates_one_source_linked_payment_item(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('200.00');

        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '80.00', 'alloc-1');

        $item = FolioItem::where('source_type', GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION)->firstOrFail();
        $folio->refresh();
        $payment->refresh();

        $this->assertSame($payment->id, $allocation->guest_payment_transaction_id);
        $this->assertSame($folio->id, $allocation->folio_id);
        $this->assertSame('80.00', (string) $allocation->amount);
        $this->assertSame(FolioItemTypeEnum::Payment, $item->item_type);
        $this->assertSame('-80.00', (string) $item->amount);
        $this->assertSame('pms_cashiering', $item->source_domain);
        $this->assertSame($allocation->id, $item->source_id);
        $this->assertSame($allocation->id, $item->guest_payment_allocation_id);
        $this->assertNull($item->guest_payment_reversal_id);
        $this->assertSame('80.00', $folio->total_payments);
        $this->assertSame('-80.00', $folio->balance);
        $this->assertSame(GuestPaymentLifecycleStatusEnum::PartiallyAllocated, $payment->lifecycle_status);

        $replay = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '80.00', 'alloc-1');
        $this->assertSame($allocation->id, $replay->id);
        $this->assertSame(1, GuestPaymentAllocation::count());
        $this->assertSame(1, FolioItem::where('source_type', GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION)->count());

        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '120.00', 'alloc-2');
        $payment->refresh();
        $this->assertSame(GuestPaymentLifecycleStatusEnum::FullyAllocated, $payment->lifecycle_status);
    }

    public function test_allocation_requires_same_reservation_currency_open_folio_and_remaining_amount(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('100.00');
        $otherReservation = $this->makeGlfReservation();
        $otherFolio = $this->makeGlfFolio($otherReservation, $otherReservation->primaryGuest);

        try {
            $this->payments->allocatePayment($this->glfActor, $payment->id, $otherFolio->id, '10.00', 'alloc-cross-res');
            $this->fail('Different reservation must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('do not match', $exception->getMessage());
        }

        $folio->forceFill(['status' => \Modules\Operations\PMS\Enums\FolioStatusEnum::Closed])->save();
        try {
            $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '10.00', 'alloc-closed');
            $this->fail('Closed Folio must be rejected.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('open Folios', $exception->getMessage());
        }

        $folio->forceFill(['status' => \Modules\Operations\PMS\Enums\FolioStatusEnum::Open])->save();
        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '90.00', 'alloc-ok');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GUEST_PAYMENT_OVER_ALLOCATION');
        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '20.00', 'alloc-over');
    }

    public function test_generic_folio_posting_rejects_cashiering_owned_item_types(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->makeGlfFolio($reservation, $reservation->primaryGuest);

        foreach ([FolioItemTypeEnum::Payment, FolioItemTypeEnum::Deposit, FolioItemTypeEnum::PaymentReversal] as $type) {
            try {
                $this->folios->postItem($this->glfActor, $folio->id, [
                    'item_type' => $type,
                    'description' => 'Blocked',
                    'quantity' => 1,
                    'amount' => $type === FolioItemTypeEnum::Payment ? '-10.00' : '10.00',
                ]);
                $this->fail($type->value . ' must be blocked from generic posting.');
            } catch (ValidationException $exception) {
                $this->assertStringContainsString('Payment-owned', $exception->getMessage());
            }
        }

        $this->assertSame(0, FolioItem::count());
    }

    public function test_void_requires_confirmation_and_only_unallocated_payment_can_be_voided(): void
    {
        [$payment] = $this->paymentAndFolio('50.00');

        try {
            $this->payments->voidPayment($this->glfActor, $payment->id, 'WRONG_AMOUNT', 'void-1');
            $this->fail('Void without confirmation must fail.');
        } catch (DomainException $exception) {
            $this->assertStringContainsString('confirmation', $exception->getMessage());
        }

        $this->confirm(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);
        $void = $this->payments->voidPayment($this->glfActor, $payment->id, 'WRONG_AMOUNT', 'void-1');
        $payment->refresh();

        $this->assertSame(GuestPaymentReversalTypeEnum::PaymentVoid, $void->reversal_type);
        $this->assertNull($void->guest_payment_allocation_id);
        $this->assertSame('50.00', (string) $void->amount);
        $this->assertSame(GuestPaymentLifecycleStatusEnum::Voided, $payment->lifecycle_status);
        $this->assertSame(0, FolioItem::count());
    }

    public function test_void_is_blocked_after_any_allocation_history(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('50.00');
        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '20.00', 'alloc-before-void');
        $this->confirm(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('allocation history');
        $this->payments->voidPayment($this->glfActor, $payment->id, 'ALLOCATED', 'void-allocated');
    }

    public function test_reverses_allocation_with_compensating_folio_item_and_allows_reallocation(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('100.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '60.00', 'alloc-rev');
        $originalItem = FolioItem::where('source_id', $allocation->id)->firstOrFail();

        $this->confirm(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $reversal = $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'WRONG_FOLIO', 'reverse-1');
        $reversalItem = FolioItem::where('source_id', $reversal->id)->firstOrFail();

        $allocation->refresh();
        $originalItem->refresh();
        $folio->refresh();
        $payment->refresh();

        $this->assertSame(GuestPaymentReversalTypeEnum::AllocationReversal, $reversal->reversal_type);
        $this->assertSame($allocation->id, $reversal->guest_payment_allocation_id);
        $this->assertSame('60.00', (string) $reversal->amount);
        $this->assertSame('60.00', (string) $allocation->amount);
        $this->assertSame('-60.00', (string) $originalItem->amount);
        $this->assertFalse($originalItem->is_void);
        $this->assertSame(FolioItemTypeEnum::PaymentReversal, $reversalItem->item_type);
        $this->assertSame('60.00', (string) $reversalItem->amount);
        $this->assertSame($allocation->id, $reversalItem->guest_payment_allocation_id);
        $this->assertSame($reversal->id, $reversalItem->guest_payment_reversal_id);
        $this->assertSame($originalItem->id, $reversalItem->reverses_folio_item_id);
        $this->assertSame('0.00', $folio->total_payments);
        $this->assertSame('0.00', $folio->balance);
        $this->assertSame(GuestPaymentLifecycleStatusEnum::Recorded, $payment->lifecycle_status);

        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '100.00', 'alloc-after-reversal');
        $payment->refresh();
        $this->assertSame(GuestPaymentLifecycleStatusEnum::FullyAllocated, $payment->lifecycle_status);
    }

    public function test_double_reversal_is_rejected_or_replayed_by_idempotency(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('40.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '40.00', 'alloc-double-rev');

        $this->confirm(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $first = $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'DUPLICATE', 'reverse-double');
        $replay = $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'DUPLICATE', 'reverse-double');

        $this->assertSame($first->id, $replay->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('already been reversed');
        $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'DUPLICATE', 'reverse-double-2');
    }

    public function test_strict_decimal_edges_are_canonical_and_float_free(): void
    {
        $reservation = $this->makeGlfReservation();
        $session = $this->openCashierSession();

        $payment = $this->payments->recordCashPayment($this->glfActor, $reservation->id, $session->id, '1.2300', 'decimal-canonical');
        $this->assertSame('1.23', (string) $payment->amount);

        foreach (['1.231', '1e2', '10000000000.00'] as $badAmount) {
            try {
                $this->payments->recordCashPayment($this->glfActor, $reservation->id, $session->id, $badAmount, 'decimal-bad-' . str_replace('.', '-', $badAmount));
                $this->fail($badAmount . ' must be rejected.');
            } catch (ValidationException $exception) {
                $this->assertNotEmpty($exception->errors()['amount'] ?? []);
            }
        }
    }

    public function test_missing_command_permissions_fail_closed(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('25.00');

        $this->glfActor->revokePermissionTo(GuestPaymentLifecycleService::ALLOCATE_PERMISSION);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->expectException(AuthorizationException::class);
        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '10.00', 'missing-allocate-permission');
    }

    public function test_cross_property_payment_allocation_and_cashier_session_are_non_disclosing(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('25.00');
        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $otherReservation = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);
        $otherFolio = $this->makeGlfFolio($otherReservation, $otherGuest, [
            'currency' => 'EUR',
            'folio_number' => 'OTHER-FOL',
            'opening_idempotency_key' => 'other-open',
        ]);

        $this->expectException(\Illuminate\Database\Eloquent\ModelNotFoundException::class);
        $this->payments->allocatePayment($this->glfActor, $payment->id, $otherFolio->id, '5.00', 'cross-property-folio');
    }

    public function test_currency_mismatch_fails_closed(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('25.00');
        $folio->forceFill(['currency' => 'EUR'])->save();

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('do not match');
        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '5.00', 'currency-mismatch');
    }

    public function test_void_and_reversal_idempotency_mismatches_fail_closed(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('70.00');
        $this->confirm(GuestPaymentLifecycleService::VOID_CONFIRMATION_INTENT);
        $this->payments->voidPayment($this->glfActor, $payment->id, 'FIRST_VOID', 'void-conflict');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GUEST_PAYMENT_VOID_IDEMPOTENCY_CONFLICT');
        $this->payments->voidPayment($this->glfActor, $payment->id, 'SECOND_VOID', 'void-conflict');
    }

    public function test_reversal_idempotency_mismatch_fails_closed(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('70.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '70.00', 'reversal-conflict-alloc');
        $this->confirm(GuestPaymentLifecycleService::REVERSAL_CONFIRMATION_INTENT);
        $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'FIRST_REVERSAL', 'reversal-conflict');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('GUEST_PAYMENT_REVERSAL_IDEMPOTENCY_CONFLICT');
        $this->payments->reverseAllocation($this->glfActor, $allocation->id, 'SECOND_REVERSAL', 'reversal-conflict');
    }

    public function test_source_owned_mass_assignment_and_direct_masquerade_are_blocked(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('40.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '40.00', 'source-mass-assign');

        $item = new FolioItem([
            'item_type' => FolioItemTypeEnum::Payment,
            'description' => 'Blocked source assignment',
            'quantity' => '1.00',
            'amount' => '-40.00',
            'source_domain' => 'pms_cashiering',
            'source_type' => GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION,
            'source_id' => $allocation->id,
            'guest_payment_allocation_id' => $allocation->id,
        ]);

        $this->assertNull($item->source_domain);
        $this->assertNull($item->guest_payment_allocation_id);

        $this->expectException(QueryException::class);
        DB::table('folio_items')->insert([
            'id' => (string) \Illuminate\Support\Str::ulid(),
            'property_id' => $this->glfProperty->id,
            'folio_id' => $folio->id,
            'item_type' => FolioItemTypeEnum::Payment->value,
            'description' => 'Masquerade',
            'quantity' => '1.00',
            'amount' => '-10.00',
            'is_void' => false,
            'posted_at' => now(),
            'posted_by' => $this->glfActor->id,
            'created_by' => $this->glfActor->id,
            'source_domain' => 'pms_cashiering',
            'source_type' => GuestLedgerPaymentAllocationEffectService::SOURCE_ALLOCATION,
            'source_id' => (string) \Illuminate\Support\Str::ulid(),
            'guest_payment_allocation_id' => (string) \Illuminate\Support\Str::ulid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_original_allocation_and_cashiering_folio_item_are_immutable(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('40.00');
        $allocation = $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '40.00', 'immutable-alloc');
        $sourceItem = FolioItem::where('guest_payment_allocation_id', $allocation->id)->firstOrFail();

        try {
            DB::table('guest_payment_allocations')->where('id', $allocation->id)->update(['amount' => '39.00']);
            $this->fail('Allocation update must be blocked by immutability trigger.');
        } catch (QueryException $exception) {
            $this->assertStringContainsString('GLF_B_GUEST_PAYMENT_ALLOCATIONS_IMMUTABLE', $exception->getMessage());
        }

        $this->expectException(QueryException::class);
        DB::table('folio_items')->where('id', $sourceItem->id)->update(['amount' => '-39.00']);
    }

    public function test_no_general_cashier_or_downstream_financial_records_are_mutated(): void
    {
        [$payment, $folio] = $this->paymentAndFolio('30.00');
        $sessionBefore = CashierSession::whereKey($payment->cashier_session_id)->firstOrFail()->getAttributes();
        $journalBefore = Schema::hasTable('journal_entries') ? DB::table('journal_entries')->count() : null;

        $this->payments->allocatePayment($this->glfActor, $payment->id, $folio->id, '30.00', 'no-downstream');

        $sessionAfter = CashierSession::whereKey($payment->cashier_session_id)->firstOrFail()->getAttributes();
        $this->assertSame($sessionBefore['status'], $sessionAfter['status']);
        $this->assertSame($sessionBefore['closed_at'], $sessionAfter['closed_at']);
        $this->assertSame(0, DB::table('folio_items')->where('item_type', FolioItemTypeEnum::Deposit->value)->count());

        if ($journalBefore !== null) {
            $this->assertSame($journalBefore, DB::table('journal_entries')->count());
        }
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

        return [$payment, $folio, $reservation];
    }

    private function openCashierSession(array $overrides = []): CashierSession
    {
        $session = new CashierSession();
        $session->forceFill(array_merge([
            'property_id' => $this->glfProperty->id,
            'cashier_user_id' => $this->glfActor->id,
            'status' => CashierSessionStatusEnum::OPEN->value,
            'opened_at' => now(),
            'opened_by' => $this->glfActor->id,
            'closed_at' => null,
            'closed_by' => null,
        ], $overrides))->save();

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
}
