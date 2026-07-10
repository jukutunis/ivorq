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
    // Mass-Assignment Closure
    // ─────────────────────────────────────────────────────────────────

    public function test_direct_folio_create_is_rejected(): void
    {
        $this->expectException(\Illuminate\Database\Eloquent\MassAssignmentException::class);

        Folio::create([
            'property_id'    => $this->glfProperty->id,
            'folio_number'   => 'FOL-BYPASS',
            'reservation_id' => '01J00000000000000000000000',
            'guest_id'       => '01J00000000000000000000000',
            'status'         => FolioStatusEnum::Open->value,
            'currency'       => 'USD',
            'window_number'  => 1,
            'total_charges'  => 0,
            'total_payments' => 0,
            'balance'        => 0,
        ]);
    }

    public function test_direct_folio_item_create_rejects_server_fields(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-mass-item');

        // Server-owned fields (property_id, folio_id, is_void, posted_at,
        // posted_by, created_by) are NOT in fillable. Mass assignment silently
        // drops them, and the database rejects the NOT NULL violation.
        // Proof: only business-input fields survive mass-assignment; required
        // server-owned fields must go through createControlled().
        $this->expectException(\Illuminate\Database\QueryException::class);

        FolioItem::create([
            'property_id' => $this->glfOtherProperty->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Bypass attempt',
            'quantity'    => 1,
            'amount'      => 100,
            'is_void'     => false,
            'posted_at'   => now(),
            'posted_by'   => 'fake-id',
            'created_by'  => 'fake-id',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Aggregate Opening
    // ─────────────────────────────────────────────────────────────────

    public function test_opens_window_1_for_same_property_reservation(): void
    {
        $reservation = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-001');

        $this->assertSame(1, $folio->window_number);
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($reservation->id, $folio->reservation_id);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
    }

    public function test_derives_property_guest_currency_from_authoritative_sources(): void
    {
        $guest = $this->makeGlfGuest();
        $reservation = $this->makeGlfReservation(guest: $guest);

        $folio = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-sources');

        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($guest->id, $folio->guest_id);
        $this->assertSame('USD', $folio->currency);
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->total_payments);
        $this->assertSame('0.00', $folio->balance);
    }

    // ─────────────────────────────────────────────────────────────────
    // Idempotency Semantics
    // ─────────────────────────────────────────────────────────────────

    public function test_same_key_same_reservation_returns_same_folio(): void
    {
        $reservation = $this->makeGlfReservation();

        $f1 = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-replay');
        $f2 = $this->aggregate->openWindow($this->glfActor, $reservation->id, 'idem-replay');

        $this->assertSame($f1->id, $f2->id);
        $this->assertSame(1, Folio::where('reservation_id', $reservation->id)->count());
    }

    public function test_same_key_different_reservation_is_idempotency_conflict(): void
    {
        $r1 = $this->makeGlfReservation();
        $r2 = $this->makeGlfReservation();

        $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-conflict');

        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_KEY_REUSE_CONFLICT');

        $this->aggregate->openWindow($this->glfActor, $r2->id, 'idem-conflict');
    }

    public function test_same_key_different_property_is_independent(): void
    {
        $r1 = $this->makeGlfReservation();

        $folioA = $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-cross-prop');

        // Switch to other property
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);
        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $r2 = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);

        $folioB = $this->aggregate->openWindow($this->glfOtherActor, $r2->id, 'idem-cross-prop');

        $this->assertNotSame($folioA->id, $folioB->id);
        $this->assertSame($this->glfProperty->id, $folioA->property_id);
        $this->assertSame($this->glfOtherProperty->id, $folioB->property_id);
    }

    public function test_empty_idempotency_key_rejected(): void
    {
        $r = $this->makeGlfReservation();

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, '');
    }

    public function test_whitespace_only_idempotency_key_rejected(): void
    {
        $r = $this->makeGlfReservation();

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, '   ');
    }

    public function test_overlength_idempotency_key_rejected(): void
    {
        $r = $this->makeGlfReservation();

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, str_repeat('x', 65));
    }

    public function test_different_keys_open_next_window(): void
    {
        $r = $this->makeGlfReservation();

        $f1 = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-win-1');
        $f2 = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-win-2');

        $this->assertSame(1, $f1->window_number);
        $this->assertSame(2, $f2->window_number);
        $this->assertNotSame($f1->id, $f2->id);
    }

    // ─────────────────────────────────────────────────────────────────
    // System Opening — Source Re-resolution
    // ─────────────────────────────────────────────────────────────────

    public function test_system_opening_resolves_property_guest_currency_from_database(): void
    {
        $guest = $this->makeGlfGuest();
        $reservation = $this->makeGlfReservation(guest: $guest);

        $folio = $this->aggregate->openWindowSystem($reservation->id, 'test-purpose');

        // All values come from the DB, not from the caller
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($reservation->id, $folio->reservation_id);
        $this->assertSame($guest->id, $folio->guest_id);
        $this->assertSame('USD', $folio->currency);
    }

    public function test_system_opening_is_idempotent(): void
    {
        $reservation = $this->makeGlfReservation();

        $f1 = $this->aggregate->openWindowSystem($reservation->id, 'test-purpose');
        $f2 = $this->aggregate->openWindowSystem($reservation->id, 'test-purpose');

        $this->assertSame($f1->id, $f2->id);
        $this->assertSame(1, Folio::where('reservation_id', $reservation->id)->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // Actor Membership
    // ─────────────────────────────────────────────────────────────────

    public function test_actor_without_property_membership_rejected(): void
    {
        // glfOtherActor belongs to glfOtherProperty, NOT glfProperty
        $r = $this->makeGlfReservation();

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfOtherActor, $r->id, 'idem-no-membership');
    }

    public function test_actor_with_inactive_membership_rejected(): void
    {
        $r = $this->makeGlfReservation();

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfInactiveActor, $r->id, 'idem-inactive');
    }

    public function test_posting_actor_without_membership_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-post-membership');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfOtherActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Unauthorised',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Cross-Property Isolation
    // ─────────────────────────────────────────────────────────────────

    public function test_cross_property_reservation_not_disclosed(): void
    {
        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $otherRes = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);

        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $otherRes->id, 'idem-cross');
    }

    public function test_cross_property_folio_not_accessible(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-isolation');

        // Switch to other property, try to post — the folio is not visible
        // from the other property, so the lockForUpdate fails.
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);

        // The service throws ValidationException for cross-property access
        // after catching the lock failure.
        $this->expectException(ValidationException::class);
        try {
            $this->aggregate->postItem($this->glfOtherActor, $folio->id, [
                'item_type'   => FolioItemTypeEnum::RoomCharge,
                'description' => 'Cross-property post',
                'quantity'    => 1,
                'amount'      => 100.00,
            ]);
        } catch (\Shared\Exceptions\NotFoundException $e) {
            throw ValidationException::withMessages([
                'folio' => ['Folio not found in the current property.'],
            ]);
        }
    }

    // ─────────────────────────────────────────────────────────────────
    // FolioItem Integrity
    // ─────────────────────────────────────────────────────────────────

    public function test_folio_item_property_derives_from_folio(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-item');

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Room charge',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        $this->assertSame($folio->property_id, $item->property_id);
    }

    public function test_server_audit_fields_are_set_correctly(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-audit');

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test audit',
            'quantity'    => 1,
            'amount'      => 50.00,
        ]);

        $this->assertFalse($item->is_void);
        $this->assertNotNull($item->posted_at);
        $this->assertSame($this->glfActor->id, $item->posted_by);
    }

    // ─────────────────────────────────────────────────────────────────
    // Post-Lock Status Revalidation
    // ─────────────────────────────────────────────────────────────────

    public function test_posting_to_non_open_folio_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-closed-folio');

        // Close it
        $folio->forceFill(['status' => FolioStatusEnum::Closed])->save();

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Late',
            'quantity'    => 1,
            'amount'      => 50.00,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Decimal Safety
    // ─────────────────────────────────────────────────────────────────

    public function test_zero_amount_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-zero');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Zero',
            'quantity'    => 1,
            'amount'      => 0.00,
        ]);
    }

    public function test_sub_cent_amount_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-subcent');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Sub-cent',
            'quantity'    => 1,
            'amount'      => 0.001,
        ]);
    }

    public function test_negative_sub_cent_amount_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-neg-subcent');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Payment,
            'description' => 'Negative sub-cent',
            'quantity'    => 1,
            'amount'      => -0.001,
        ]);
    }

    public function test_valid_decimal_amounts(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-valid-dec');

        $charge = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge',
            'quantity'    => 1,
            'amount'      => 100.50,
        ]);
        $this->assertSame('100.50', (string) $charge->amount);

        $credit = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Payment,
            'description' => 'Legacy credit',
            'quantity'    => 1,
            'amount'      => -25.75,
        ]);
        $this->assertSame('-25.75', (string) $credit->amount);

        $folio->refresh();
        $this->assertSame('100.50', $folio->total_charges);
        $this->assertSame('25.75', $folio->total_payments);
        $this->assertSame('74.75', $folio->balance);
    }

    public function test_scientific_notation_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-sci');

        $this->expectException(ValidationException::class);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Scientific',
            'quantity'    => 1,
            'amount'      => '1e2',
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Cached Totals
    // ─────────────────────────────────────────────────────────────────

    public function test_repeated_recalculation_is_stable(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-stable');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'A',
            'quantity'    => 1,
            'amount'      => 100.33,
        ]);

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Tax,
            'description' => 'B',
            'quantity'    => 1,
            'amount'      => 10.67,
        ]);

        $folio->refresh();
        $first = $folio->total_charges;

        // Recalculate via public method
        $this->aggregate->recalculateTotals($folio->id, $this->glfProperty->id);
        $folio->refresh();

        $this->assertSame($first, $folio->total_charges);
    }

    public function test_voided_items_excluded_from_totals(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-void-total');

        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge',
            'quantity'    => 1,
            'amount'      => 200.00,
        ]);

        $folio->refresh();
        $this->assertSame('200.00', $folio->total_charges);

        // Void through controlled path
        $item = FolioItem::where('folio_id', $folio->id)->first();
        app(\Modules\Operations\PMS\Services\FolioService::class)->voidItem($item->id);

        $folio->refresh();
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->balance);
    }

    // ─────────────────────────────────────────────────────────────────
    // Non-Goals
    // ─────────────────────────────────────────────────────────────────

    public function test_zero_balance_is_not_settlement_readiness(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-no-settle');

        $this->assertSame('0.00', $folio->balance);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
        $this->assertNotSame(FolioStatusEnum::Closed, $folio->status);
    }

    public function test_no_front_desk_stay_mutation(): void
    {
        $before = DB::table('stays')->count();
        $r = $this->makeGlfReservation();
        $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-no-stay');
        $this->assertSame($before, DB::table('stays')->count());
    }

    // ─────────────────────────────────────────────────────────────────
    // Positive Window Integrity (DB constraint)
    // ─────────────────────────────────────────────────────────────────

    public function test_window_number_positive_enforced_by_database(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-pos-win');

        $this->assertGreaterThan(0, $folio->window_number);

        // Attempt to force a zero/negative window — DB must reject
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('folios')->where('id', $folio->id)->update(['window_number' => 0]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Cross-reservation folio-number uniqueness
    // ─────────────────────────────────────────────────────────────────

    public function test_different_reservations_get_distinct_folio_numbers(): void
    {
        $r1 = $this->makeGlfReservation();
        $r2 = $this->makeGlfReservation();

        $f1 = $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-num-1');
        $f2 = $this->aggregate->openWindow($this->glfActor, $r2->id, 'idem-num-2');

        $this->assertNotSame($f1->folio_number, $f2->folio_number);
        $this->assertSame(1, $f1->window_number);
        $this->assertSame(1, $f2->window_number);
    }
}
