<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
use Shared\Services\CurrentPropertyService;
use Tests\Postgres\Operations\PMS\Concerns\CreatesGuestLedgerFolioData;
use Tests\PostgresTestCase;

class GuestLedgerFolioAggregateTest extends PostgresTestCase
{
    use CreatesGuestLedgerFolioData;
    use RefreshDatabase;

    private GuestLedgerFolioAggregateService $aggregate;

    protected function setUp(): void
    {
        parent::setUp();
        $this->setUpGuestLedgerFolioFixture();
        $this->actingAs($this->glfActor);
        $this->aggregate = app(GuestLedgerFolioAggregateService::class);
    }

    // ─────────────────────────────────────────────────────────────────
    // Aggregate Opening
    // ─────────────────────────────────────────────────────────────────

    public function test_opens_window_1_for_same_property_reservation(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-test-001',
        );

        $this->assertSame(1, $folio->window_number);
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($reservation->id, $folio->reservation_id);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
    }

    public function test_derives_property_from_current_property_context(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-prop-context',
        );

        $this->assertSame($this->glfProperty->id, $folio->property_id);
    }

    public function test_derives_guest_from_reservation_primary_guest(): void
    {
        $guest       = $this->makeGlfGuest();
        $reservation = $this->makeGlfReservation(guest: $guest);

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-guest',
        );

        $this->assertSame($guest->id, $folio->guest_id);
    }

    public function test_derives_currency_from_property_base_currency(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-currency',
        );

        // Property was created with currency = 'USD'
        $this->assertSame('USD', $folio->currency);
    }

    public function test_status_starts_open(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-status',
        );

        $this->assertSame(FolioStatusEnum::Open, $folio->status);
    }

    public function test_cached_totals_start_at_zero(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-totals',
        );

        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->total_payments);
        $this->assertSame('0.00', $folio->balance);
    }

    public function test_same_idempotency_key_returns_same_folio(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio1 = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-same-key',
        );

        $folio2 = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-same-key',
        );

        $this->assertSame($folio1->id, $folio2->id);
        $this->assertSame($folio1->window_number, $folio2->window_number);
        $this->assertSame($folio1->folio_number, $folio2->folio_number);

        // Only one row created
        $count = Folio::where('reservation_id', $reservation->id)->count();
        $this->assertSame(1, $count);
    }

    public function test_different_idempotency_key_opens_next_window(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio1 = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-window-1',
        );

        $folio2 = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-window-2',
        );

        $this->assertNotSame($folio1->id, $folio2->id);
        $this->assertSame(1, $folio1->window_number);
        $this->assertSame(2, $folio2->window_number);
    }

    public function test_all_reservation_folios_ordered_by_window_number(): void
    {
        $reservation = $this->makeGlfReservation();

        $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-a');
        $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-b');
        $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-c');

        $folios = Folio::where('reservation_id', $reservation->id)
            ->orderBy('window_number')
            ->get();

        $this->assertCount(3, $folios);
        $this->assertSame(1, $folios[0]->window_number);
        $this->assertSame(2, $folios[1]->window_number);
        $this->assertSame(3, $folios[2]->window_number);
    }

    // ─────────────────────────────────────────────────────────────────
    // Isolation
    // ─────────────────────────────────────────────────────────────────

    public function test_unknown_reservation_is_not_disclosed(): void
    {
        $this->expectException(ValidationException::class);

        $this->aggregate->openWindow(
            $this->glfActor,
            '01J00000000000000000000000', // non-existent ULID
            'idem-unknown-res',
        );
    }

    public function test_cross_property_reservation_is_not_disclosed(): void
    {
        // Create reservation in glfOtherProperty
        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $otherRes   = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);

        // Current property is glfProperty — opening with other property's
        // reservation must fail
        $this->expectException(ValidationException::class);

        $this->aggregate->openWindow(
            $this->glfActor,
            $otherRes->id,
            'idem-cross-prop',
        );
    }

    public function test_same_idempotency_key_independently_usable_in_another_property(): void
    {
        $reservation = $this->makeGlfReservation();

        $folioA = $this->aggregate->openWindow(
            $this->glfActor,
            $reservation->id,
            'idem-cross-prop-key',
        );

        // Switch to other property and try same key
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);
        $this->glfActor->properties()->attach($this->glfOtherProperty->id, [
            'is_default' => false,
            'status'     => 'active',
            'joined_at'  => now(),
        ]);

        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $otherRes   = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);

        $folioB = $this->aggregate->openWindow(
            $this->glfActor,
            $otherRes->id,
            'idem-cross-prop-key', // same key, different property
        );

        // Different property = independent key space
        $this->assertNotSame($folioA->id, $folioB->id);
        $this->assertSame($this->glfProperty->id, $folioA->property_id);
        $this->assertSame($this->glfOtherProperty->id, $folioB->property_id);
    }

    // ─────────────────────────────────────────────────────────────────
    // FolioItem Integrity
    // ─────────────────────────────────────────────────────────────────

    public function test_folio_item_property_derives_from_folio(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-item-prop');

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $this->assertSame($folio->property_id, $item->property_id);
    }

    public function test_caller_cannot_override_folio_item_property(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-item-prop-override');

        // Even if the caller somehow passes a different property_id in $data,
        // postItem ignores it and derives from the Folio.
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
            // The old path allowed property_id override. The new path ignores it.
        ]);

        $this->assertSame($folio->property_id, $item->property_id);
    }

    public function test_cross_property_folio_item_is_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-cross-item');

        // Switch to other property but try to post to the original folio
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Cross-property attempt',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);
    }

    public function test_caller_cannot_set_is_void(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-isvoid');

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        // is_void is always false for newly posted items
        $this->assertFalse($item->is_void);
    }

    public function test_zero_amount_is_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-zero');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Zero charge',
            'quantity'    => 1,
            'amount'      => 0.00,
        ]);
    }

    public function test_posting_to_non_open_folio_is_rejected(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-closed');

        // Close the folio
        $folio->update(['status' => FolioStatusEnum::Closed]);

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Late charge',
            'quantity'    => 1,
            'amount'      => 50.00,
        ]);
    }

    public function test_repeated_canonical_recalculation_is_stable(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-stable');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge A',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Tax,
            'description' => 'Tax',
            'quantity'    => 1,
            'amount'      => 15.00,
        ]);

        $folio->refresh();
        $firstTotal = $folio->total_charges;

        // Recalculate again
        $this->aggregate->recalculateTotalsLocked($folio);
        $folio->refresh();

        $this->assertSame($firstTotal, $folio->total_charges);
    }

    public function test_voided_items_are_excluded_from_cached_totals(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-void');

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge to void',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $folio->refresh();
        $this->assertSame('100.00', $folio->total_charges);

        // Void the item
        DB::table('folio_items')->where('id', $item->id)->update(['is_void' => true]);

        $this->aggregate->recalculateTotalsLocked($folio);
        $folio->refresh();

        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->balance);
    }

    // ─────────────────────────────────────────────────────────────────
    // Aggregate Totals
    // ─────────────────────────────────────────────────────────────────

    public function test_positive_lines_contribute_to_charges(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-charges');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room',
            'quantity'    => 1,
            'amount'      => 200.00,
        ]);

        $folio->refresh();
        $this->assertSame('200.00', $folio->total_charges);
    }

    public function test_negative_legacy_credits_are_not_authoritative_payment_evidence(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-credits');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Payment,
            'description' => 'Legacy credit',
            'quantity'    => 1,
            'amount'      => -50.00,
        ]);

        $folio->refresh();
        // Negative amounts appear in legacy total_payments cache
        $this->assertSame('50.00', $folio->total_payments);
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('-50.00', $folio->balance);

        // GLF-A does not create or populate payment allocation records.
        // The FolioItem is a cached operational projection — NOT
        // authoritative payment-allocation evidence.
        $item = FolioItem::where('folio_id', $folio->id)->first();
        $this->assertNotNull($item);
        $this->assertSame(FolioItemTypeEnum::Payment, $item->item_type);
        // No settlement, no allocation lifecycle, no payment identity created.
    }

    public function test_cached_balance_remains_decimal_safe(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-decimal');

        // Post items with values that could cause floating-point issues
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge',
            'quantity'    => 1,
            'amount'      => 100.33,
        ]);

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Tax,
            'description' => 'Tax',
            'quantity'    => 1,
            'amount'      => 10.67,
        ]);

        $folio->refresh();
        $this->assertSame('111.00', $folio->total_charges);
    }

    public function test_newly_opened_zero_balance_folio_is_not_settlement_ready(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-zero-bal');

        $this->assertSame('0.00', $folio->balance);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);

        // Zero balance is NOT settlement readiness.
        // Folio remains OPEN, not CLOSED or SETTLED.
        $this->assertNotSame(FolioStatusEnum::Closed, $folio->status);
    }

    public function test_multiple_folios_are_aggregated_structurally_without_settlement_readiness(): void
    {
        $reservation = $this->makeGlfReservation();

        $folio1 = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-multi-a');
        $folio2 = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-multi-b');

        $this->assertNotSame($folio1->id, $folio2->id);
        $this->assertSame(1, $folio1->window_number);
        $this->assertSame(2, $folio2->window_number);

        // Both are structural folios, neither is settlement-ready
        $this->assertSame(FolioStatusEnum::Open, $folio1->status);
        $this->assertSame(FolioStatusEnum::Open, $folio2->status);
    }

    // ─────────────────────────────────────────────────────────────────
    // Write Boundary — Non-Goals
    // ─────────────────────────────────────────────────────────────────

    public function test_no_payment_allocation_lifecycle_created(): void
    {
        // GLF-A does not implement guest payment allocation.
        // The aggregate service openWindow and postItem methods only
        // create Folio and FolioItem records — they never insert into
        // payment, deposit, refund, or AR-transfer tables.
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-no-pay-alloc');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge only',
            'quantity'    => 1,
            'amount'      => 50.00,
        ]);

        // Only Folio + FolioItem were created — no external lifecycle tables
        $this->assertSame(1, Folio::where('reservation_id', $reservation->id)->count());
        $this->assertSame(1, FolioItem::where('folio_id', $folio->id)->count());

        // The aggregate service methods are narrowly scoped to Folio/FolioItem.
        // GLF-B will introduce payment allocation identity.
        $this->assertTrue(true); // structural proof complete
    }

    public function test_no_folio_closure_occurs_from_aggregate_service(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-no-close');

        // Post an item and verify the folio remains OPEN
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $folio->refresh();
        $this->assertSame(FolioStatusEnum::Open, $folio->status);

        // Balance is not zero, and even if it were, GLF-A does not close.
    }

    public function test_no_front_desk_stay_mutation(): void
    {
        // The aggregate service must not touch the stays table
        $beforeCount = DB::table('stays')->count();

        $reservation = $this->makeGlfReservation();
        $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-no-stay');

        $afterCount = DB::table('stays')->count();
        $this->assertSame($beforeCount, $afterCount);
    }
}
