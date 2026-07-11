<?php

namespace Tests\Postgres\Operations\PMS;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Validation\ValidationException;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Events\FolioCreated;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Models\FolioItem;
use Modules\Operations\PMS\Services\GuestLedgerFolioAggregateService;
use Shared\Exceptions\NotFoundException;
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
        ]);
    }

    public function test_direct_folio_item_create_rejects_server_fields(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-mass-item');
        $this->expectException(\Illuminate\Database\QueryException::class);
        FolioItem::create([
            'property_id' => $this->glfOtherProperty->id,
            'folio_id'    => $folio->id,
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Bypass',
            'quantity'    => 1,
            'amount'      => 100,
        ]);
    }

    // ─────────────────────────────────────────────────────────────────
    // Aggregate Opening
    // ─────────────────────────────────────────────────────────────────

    public function test_opens_window_1(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-001');
        $this->assertSame(1, $folio->window_number);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
    }

    public function test_derives_property_guest_currency_from_authoritative_sources(): void
    {
        $guest = $this->makeGlfGuest();
        $r = $this->makeGlfReservation(guest: $guest);
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-sources');
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($guest->id, $folio->guest_id);
        $this->assertSame('USD', $folio->currency);
    }

    // ─────────────────────────────────────────────────────────────────
    // Audit Actor
    // ─────────────────────────────────────────────────────────────────

    public function test_interactive_open_records_created_by_and_updated_by_as_actor(): void
    {
        Event::fake([FolioCreated::class]);

        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-audit');

        // Database audit fields
        $this->assertSame($this->glfActor->id, $folio->created_by);
        $this->assertSame($this->glfActor->id, $folio->updated_by);

        // Event model audit fields
        Event::assertDispatched(FolioCreated::class, function (FolioCreated $event) {
            return $event->folio->created_by === $this->glfActor->id
                && $event->folio->updated_by === $this->glfActor->id;
        });
    }

    public function test_actor_auth_mismatch_rejected(): void
    {
        $r = $this->makeGlfReservation();
        // glfOtherActor is authenticated nowhere — we pass a different actor
        // while authenticated as glfActor
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('Actor identity does not match');
        $this->aggregate->openWindow($this->glfOtherActor, $r->id, 'idem-mismatch');
    }

    public function test_interactive_open_without_auth_rejected(): void
    {
        Auth::logout();
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('An authenticated actor is required');
        $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-noauth');
    }

    public function test_system_open_does_not_fabricate_an_actor(): void
    {
        Event::fake([FolioCreated::class]);

        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindowSystem($r->id, 'test-purpose');

        // Database audit fields
        $this->assertNull($folio->created_by);
        $this->assertNull($folio->updated_by);

        // Event model audit fields must also be null
        Event::assertDispatched(FolioCreated::class, function (FolioCreated $event) {
            return $event->folio->created_by === null
                && $event->folio->updated_by === null;
        });
    }

    // ─────────────────────────────────────────────────────────────────
    // Idempotency
    // ─────────────────────────────────────────────────────────────────

    public function test_same_key_replay(): void
    {
        $r = $this->makeGlfReservation();
        $f1 = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-replay');
        $f2 = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-replay');
        $this->assertSame($f1->id, $f2->id);
        $this->assertSame(1, Folio::where('reservation_id', $r->id)->count());
    }

    public function test_same_key_different_reservation_conflict(): void
    {
        $r1 = $this->makeGlfReservation();
        $r2 = $this->makeGlfReservation();
        $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-conflict');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('IDEMPOTENCY_KEY_REUSE_CONFLICT');
        $this->aggregate->openWindow($this->glfActor, $r2->id, 'idem-conflict');
    }

    public function test_empty_key_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, '');
    }

    public function test_whitespace_key_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, '   ');
    }

    public function test_overlength_key_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfActor, $r->id, str_repeat('x', 65));
    }

    // ─────────────────────────────────────────────────────────────────
    // Actor Membership
    // ─────────────────────────────────────────────────────────────────

    public function test_actor_without_membership_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfOtherActor, $r->id, 'idem-nomem');
    }

    public function test_actor_with_inactive_membership_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $this->expectException(ValidationException::class);
        $this->aggregate->openWindow($this->glfInactiveActor, $r->id, 'idem-inactive');
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

    public function test_same_key_different_property_independent(): void
    {
        $r1 = $this->makeGlfReservation();
        $fA = $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-cross-prop');
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);
        $otherGuest = $this->makeGlfGuest($this->glfOtherProperty);
        $r2 = $this->makeGlfReservation($this->glfOtherProperty, $otherGuest);
        $this->actingAs($this->glfOtherActor);
        $fB = $this->aggregate->openWindow($this->glfOtherActor, $r2->id, 'idem-cross-prop');
        $this->assertNotSame($fA->id, $fB->id);
    }

    // ─────────────────────────────────────────────────────────────────
    // Malicious Input — openWindow (browser fields ignored)
    // ─────────────────────────────────────────────────────────────────

    public function test_caller_cannot_override_folio_fields(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-malicious');
        // All values are server-resolved regardless of what any caller
        // might try to pass. These assertions prove the defaults.
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($r->id, $folio->reservation_id);
        $this->assertSame('USD', $folio->currency);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->total_payments);
        $this->assertSame('0.00', $folio->balance);
        $this->assertGreaterThan(0, $folio->window_number);
        $this->assertNotNull($folio->folio_number);
    }

    // ─────────────────────────────────────────────────────────────────
    // Malicious Input — postItem (conflicting values actually submitted)
    // ─────────────────────────────────────────────────────────────────

    /**
     * Prove that every server-owned field submitted with a conflicting
     * caller-chosen value is ignored. The persisted output must derive
     * from locked server-side sources only.
     */
    public function test_post_item_server_fields_cannot_be_overridden(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-mal-agg');

        // Submit a data array that contains EVERY server-owned field with a
        // malicious value — property from another property, folio from another
        // folio, actor from another user, caller-chosen timestamp, and is_void.
        $maliciousData = [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Malicious input test',
            'quantity'    => 2,
            'amount'      => 150.00,
            // Server-owned fields injected by caller:
            'property_id' => $this->glfOtherProperty->id,
            'folio_id'    => '01J00000000000000000000000',
            'posted_by'   => $this->glfOtherActor->id,
            'created_by'  => $this->glfOtherActor->id,
            'posted_at'   => '2020-01-01 00:00:00',
            'is_void'     => true,
        ];

        $item = $this->aggregate->postItem($this->glfActor, $folio->id, $maliciousData);

        // Every persisted value must come from the server, not the caller
        $this->assertSame($folio->property_id, $item->property_id,
            'property_id must be the locked folio property, not caller input');
        $this->assertSame($folio->id, $item->folio_id,
            'folio_id must be the target folio, not caller input');
        $this->assertSame($this->glfActor->id, $item->posted_by,
            'posted_by must be the authenticated actor, not caller input');
        $this->assertSame($this->glfActor->id, $item->created_by,
            'created_by must be the authenticated actor, not caller input');
        $this->assertFalse($item->is_void,
            'is_void must be false for a new post, not caller input');
        $this->assertNotNull($item->posted_at,
            'posted_at must be a server timestamp, not caller input');
        // posted_at should be recent, not the caller-chosen 2020 timestamp
        $this->assertNotEquals('2020-01-01 00:00:00', (string) $item->posted_at,
            'posted_at must ignore caller-supplied timestamp');
    }

    // ─────────────────────────────────────────────────────────────────
    // Post-Lock Status Revalidation
    // ─────────────────────────────────────────────────────────────────

    public function test_posting_to_non_open_folio_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-closed');
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

    private function postDecimal(string $folioId, $amount, $quantity = 1): void
    {
        $this->aggregate->postItem($this->glfActor, $folioId, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Decimal test',
            'quantity'    => $quantity,
            'amount'      => $amount,
        ]);
    }

    public function test_amount_1_239_rejected_excess_fractional(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-dec-1239');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too many decimal places');
        $this->postDecimal($folio->id, 1.239);
    }

    public function test_amount_negative_1_239_rejected_excess_fractional(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-dec-neg1239');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too many decimal places');
        $this->postDecimal($folio->id, -1.239);
    }

    public function test_amount_1_2300_accepted_as_1_23(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-dec-12300');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test',
            'quantity'    => 1,
            'amount'      => '1.2300',
        ]);
        $this->assertSame('1.23', (string) $item->amount);
    }

    public function test_quantity_1_001_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-qty-1001');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('too many decimal places');
        $this->postDecimal($folio->id, 100.00, 1.001);
    }

    public function test_quantity_1_000_accepted_as_1_00(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-qty-1000');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test',
            'quantity'    => '1.000',
            'amount'      => 100.00,
        ]);
        $this->assertSame('1.00', (string) $item->quantity);
    }

    public function test_quantity_999999_99_accepted(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-qty-max');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test',
            'quantity'    => '999999.99',
            'amount'      => 0.01,
        ]);
        $this->assertSame('999999.99', (string) $item->quantity);
    }

    public function test_quantity_1000000_rejected_overflow(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-qty-over');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds maximum');
        $this->postDecimal($folio->id, 0.01, '1000000.00');
    }

    public function test_amount_9999999999_99_accepted(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-amt-max');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Test',
            'quantity'    => 1,
            'amount'      => '9999999999.99',
        ]);
        $this->assertSame('9999999999.99', (string) $item->amount);
    }

    public function test_amount_10000000000_rejected_overflow(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-amt-over');
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage('exceeds maximum');
        $this->postDecimal($folio->id, '10000000000.00');
    }

    public function test_scientific_notation_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-sci');
        $this->expectException(ValidationException::class);
        $this->postDecimal($folio->id, '1e2');
    }

    public function test_zero_amount_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-zero');
        $this->expectException(ValidationException::class);
        $this->postDecimal($folio->id, 0.00);
    }

    // ─────────────────────────────────────────────────────────────────
    // Cached Totals
    // ─────────────────────────────────────────────────────────────────

    public function test_repeated_recalculation_stable(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-stable');
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'A', 'quantity' => 1, 'amount' => 100.33,
        ]);
        $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::Tax,
            'description' => 'B', 'quantity' => 1, 'amount' => 10.67,
        ]);
        $folio->refresh();
        $first = $folio->total_charges;
        $this->aggregate->recalculateTotals($folio->id, $this->glfProperty->id);
        $folio->refresh();
        $this->assertSame($first, $folio->total_charges);
    }

    // ─────────────────────────────────────────────────────────────────
    // Controlled Void
    // ─────────────────────────────────────────────────────────────────

    public function test_void_requires_active_membership(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-void-mem');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'T', 'quantity' => 1, 'amount' => 100.00,
        ]);
        $this->expectException(ValidationException::class);
        $this->aggregate->voidItem($this->glfOtherActor, $item->id);
    }

    /**
     * Non-disclosure proof: cross-property and unknown item IDs produce
     * identical NotFoundException. Must not lock or mutate sibling-property
     * rows before rejection (findIdentityForProperty is lock-free).
     */
    public function test_cross_property_and_unknown_item_produce_identical_non_disclosing_outcome(): void
    {
        // ── Setup: create item in Property A while authenticated as Actor A ──
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-void-cross-proof');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Cross-property disclosure test',
            'quantity'    => 1,
            'amount'      => 100.00,
        ]);

        // Capture pre-attempt state
        $folio->refresh();
        $totalsBefore = [
            'charges'  => $folio->total_charges,
            'payments' => $folio->total_payments,
            'balance'  => $folio->balance,
        ];
        $this->assertFalse($item->fresh()->is_void, 'Item must be active before attempts.');

        // ── Switch to Property B, authenticate as Actor B ──
        app(CurrentPropertyService::class)->setPropertyId($this->glfOtherProperty->id);
        Auth::login($this->glfOtherActor);

        // ── Attempt 1: real cross-property ID ──
        $crossException = null;
        try {
            $this->aggregate->voidItem($this->glfOtherActor, $item->id);
        } catch (\Throwable $e) {
            $crossException = $e;
        }

        // ── Attempt 2: random unknown ULID ──
        $unknownException = null;
        try {
            $this->aggregate->voidItem($this->glfOtherActor, '01J00000000000000000000000');
        } catch (\Throwable $e) {
            $unknownException = $e;
        }

        // ── Assertions ──

        // Both must throw
        $this->assertNotNull($crossException, 'Cross-property void must throw.');
        $this->assertNotNull($unknownException, 'Unknown-ID void must throw.');

        // Identical exception type (NotFoundException)
        $this->assertInstanceOf(NotFoundException::class, $crossException,
            'Cross-property must produce NotFoundException.');
        $this->assertInstanceOf(NotFoundException::class, $unknownException,
            'Unknown ID must produce NotFoundException.');

        // Equivalent outward behavior — same class
        $this->assertSame(
            get_class($crossException),
            get_class($unknownException),
            'Cross-property and unknown-ID must produce the same exception class.'
        );

        // ── Sibling-property item unchanged ──
        $item->refresh();
        $this->assertFalse($item->is_void, 'Property-A item must not be voided.');

        // ── Sibling-property totals unchanged ──
        $folio->refresh();
        $this->assertSame($totalsBefore['charges'], $folio->total_charges,
            'Property-A total_charges must be unchanged.');
        $this->assertSame($totalsBefore['payments'], $folio->total_payments,
            'Property-A total_payments must be unchanged.');
        $this->assertSame($totalsBefore['balance'], $folio->balance,
            'Property-A balance must be unchanged.');
    }

    public function test_repeated_void_rejected(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-void-repeat');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'T', 'quantity' => 1, 'amount' => 100.00,
        ]);
        $this->aggregate->voidItem($this->glfActor, $item->id);
        $this->expectException(ValidationException::class);
        $this->aggregate->voidItem($this->glfActor, $item->id);
    }

    public function test_void_updates_totals_atomically(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-void-atomic');
        $item = $this->aggregate->postItem($this->glfActor, $folio->id, [
            'item_type'   => FolioItemTypeEnum::RoomCharge,
            'description' => 'Charge', 'quantity' => 1, 'amount' => 200.00,
        ]);
        $folio->refresh();
        $this->assertSame('200.00', $folio->total_charges);

        $this->aggregate->voidItem($this->glfActor, $item->id);
        $folio->refresh();
        $this->assertSame('0.00', $folio->total_charges);
        $this->assertSame('0.00', $folio->balance);
    }

    // ─────────────────────────────────────────────────────────────────
    // System Opening Source Re-resolution
    // ─────────────────────────────────────────────────────────────────

    public function test_system_opening_resolves_sources_from_db(): void
    {
        $guest = $this->makeGlfGuest();
        $r = $this->makeGlfReservation(guest: $guest);
        $folio = $this->aggregate->openWindowSystem($r->id, 'test');
        $this->assertSame($this->glfProperty->id, $folio->property_id);
        $this->assertSame($guest->id, $folio->guest_id);
        $this->assertSame('USD', $folio->currency);
        $this->assertNull($folio->created_by);
    }

    // ─────────────────────────────────────────────────────────────────
    // Non-Goals
    // ─────────────────────────────────────────────────────────────────

    public function test_zero_balance_is_not_settlement_readiness(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-nosettle');
        $this->assertSame('0.00', $folio->balance);
        $this->assertSame(FolioStatusEnum::Open, $folio->status);
    }

    public function test_no_front_desk_stay_mutation(): void
    {
        $before = DB::table('stays')->count();
        $r = $this->makeGlfReservation();
        $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-nostay');
        $this->assertSame($before, DB::table('stays')->count());
    }

    public function test_window_number_positive_enforced_by_database(): void
    {
        $r = $this->makeGlfReservation();
        $folio = $this->aggregate->openWindow($this->glfActor, $r->id, 'idem-poswin');
        $this->assertGreaterThan(0, $folio->window_number);
        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('folios')->where('id', $folio->id)->update(['window_number' => 0]);
    }

    public function test_different_reservations_get_distinct_folio_numbers(): void
    {
        $r1 = $this->makeGlfReservation();
        $r2 = $this->makeGlfReservation();
        $f1 = $this->aggregate->openWindow($this->glfActor, $r1->id, 'idem-num1');
        $f2 = $this->aggregate->openWindow($this->glfActor, $r2->id, 'idem-num2');
        $this->assertNotSame($f1->folio_number, $f2->folio_number);
        $this->assertSame(1, $f1->window_number);
        $this->assertSame(1, $f2->window_number);
    }
}
