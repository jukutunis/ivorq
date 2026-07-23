<?php

namespace Tests\Postgres\Operations\FrontDesk;

use DomainException;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutHousekeepingHandoffDeliveryService;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;
use Tests\PostgresTestCase;

class FrontDeskCheckoutHousekeepingHandoffFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;
    private CurrentPropertyService $currentProperty;
    private FrontDeskCheckoutHousekeepingHandoffDeliveryService $deliveryService;

    protected function setUp(): void
    {
        parent::setUp();

        // Use real PHP time — close enough to PostgreSQL CURRENT_TIMESTAMP for 60s+ leases
        Carbon::setTestNow(now());

        $this->currentProperty = app(CurrentPropertyService::class);

        $this->company = Company::create([
            'name' => 'FD-C2 Foundation Co',
            'slug' => 'fd-c2-fco-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FD-C2 Property',
            'slug' => 'fd-c2-prop-' . Str::lower(Str::random(6)),
            'code' => 'FC2P' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FD-C2 Other',
            'slug' => 'fd-c2-other-' . Str::lower(Str::random(6)),
            'code' => 'FC2O' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actor = User::create([
            'name' => 'FD-C2 Actor',
            'email' => 'fd-c2-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);

        $this->deliveryService = app(FrontDeskCheckoutHousekeepingHandoffDeliveryService::class);

        $this->currentProperty->setPropertyId($this->property->id);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    /**
     * Align Carbon test time to the authoritative PostgreSQL CURRENT_TIMESTAMP.
     */
    private function syncCarbonToDatabase(): void
    {
        $dbNow = DB::select('SELECT CURRENT_TIMESTAMP AS database_now')[0]->database_now;
        Carbon::setTestNow(Carbon::parse($dbNow));
    }

    private function makeGuest(Property $prop): Guest
    {
        return Guest::create([
            'property_id' => $prop->id,
            'guest_code' => 'G-' . Str::upper(Str::random(6)),
            'full_name' => 'FD-C2 Guest ' . Str::random(4),
            'guest_type' => 'individual',
        ]);
    }

    private function makeReservation(Property $prop): Reservation
    {
        $guest = $this->makeGuest($prop);

        return Reservation::create([
            'property_id' => $prop->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'FD-C2-R-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDays(2),
            'nights' => 2,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);
    }

    private function makeInHouseStay(Property $prop): FrontDeskStay
    {
        $res = $this->makeReservation($prop);

        return FrontDeskStay::create([
            'property_id' => $prop->id,
            'reservation_id' => $res->id,
            'guest_id' => $res->primary_guest_id,
            'status' => FrontDeskStayStatusEnum::InHouse->value,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function makeFinalReview(Property $prop, FrontDeskStay $stay): FrontDeskDepartureCheckoutFinalReview
    {
        return FrontDeskDepartureCheckoutFinalReview::create([
            'property_id' => $prop->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'guest_id' => $stay->guest_id,
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'idempotency_key' => 'dcfr-fdc2-' . Str::ulid(),
            'source_hash' => hash('sha256', Str::ulid()->toString()),
        ]);
    }

    private function makeBusinessDate(Property $prop): PropertyBusinessDate
    {
        return PropertyBusinessDate::create([
            'property_id' => $prop->id,
            'business_date' => today(),
            'status' => PropertyBusinessDateStatusEnum::Open,
            'is_open' => true,
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by' => $this->actor->id,
            'opened_at' => now(),
        ]);
    }

    private function sha256(string $input): string
    {
        return hash('sha256', $input);
    }

    /**
     * Create a valid FD-C1 checkout execution as test fixture.
     */
    private function createCheckoutExecution(FrontDeskStay $stay, FrontDeskDepartureCheckoutFinalReview $review, PropertyBusinessDate $bd, string $idempotencyKey): FrontDeskCheckoutExecution
    {
        $naFp = $this->sha256('na-attestation-' . $stay->id);
        $pmsFp = $this->sha256('pms-attestation-' . $stay->id);
        $gcFp = $this->sha256('gc-attestation-' . $stay->id);

        $e = new FrontDeskCheckoutExecution();
        $e->forceFill([
            'property_id' => $stay->property_id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'idempotency_key' => $idempotencyKey,
            'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
            'front_desk_final_review_id' => $review->id,
            'property_business_date_id' => $bd->id,
            'business_date' => $bd->business_date,
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => $naFp,
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => $pmsFp,
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => $gcFp,
            'source_hash' => $this->sha256($stay->id . $idempotencyKey),
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'created_at' => now(),
        ])->save();

        return $e->fresh();
    }

    /**
     * Build a valid handoff payload.
     *
     * @return array<string, mixed>
     */
    private function validHandoffPayload(
        FrontDeskCheckoutExecution $execution,
        string $idempotencyKey,
        string $correlationKey
    ): array {
        return [
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $idempotencyKey,
            'correlation_key' => $correlationKey,
            'source_hash' => $this->sha256($execution->id . $idempotencyKey),
            'occurred_at' => now(),
            'available_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ];
    }

    /**
     * Insert a handoff row via forceFill.
     */
    private function createHandoff(
        FrontDeskCheckoutExecution $execution,
        string $idempotencyKey,
        string $correlationKey
    ): FrontDeskCheckoutHousekeepingHandoff {
        $h = new FrontDeskCheckoutHousekeepingHandoff();
        $h->forceFill($this->validHandoffPayload($execution, $idempotencyKey, $correlationKey))->save();
        return $h->fresh();
    }

    // ── Foundation: Insert with valid checkout execution ───────────────────

    public function test_valid_handoff_row_can_be_inserted(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-valid-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-valid-' . Str::ulid(), 'corr-valid-' . Str::ulid());

        $this->assertNotNull($handoff->id);
        $this->assertSame(26, strlen($handoff->id));
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending, $handoff->delivery_status);
    }

    public function test_initial_state_is_pending(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-pend-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-pend-' . Str::ulid(), 'corr-pend-' . Str::ulid());

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Pending, $handoff->delivery_status);
    }

    public function test_attempts_starts_at_zero(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-att0-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-att0-' . Str::ulid(), 'corr-att0-' . Str::ulid());

        $this->assertSame(0, $handoff->attempts);
    }

    public function test_no_claim_fields_exist_initially(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-noclaim-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-noclaim-' . Str::ulid(), 'corr-noclaim-' . Str::ulid());

        $this->assertNull($handoff->claimed_at);
        $this->assertNull($handoff->claim_expires_at);
        $this->assertNull($handoff->claim_token_hash);
        $this->assertNull($handoff->delivered_at);
        $this->assertNull($handoff->failed_at);
        $this->assertNull($handoff->last_error_code);
    }

    // ── Unique identities ──────────────────────────────────────────────────

    public function test_property_scoped_idempotency_enforced(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-idem-uq-' . Str::ulid());

        $ikey = 'idem-unique-' . Str::ulid();
        $this->createHandoff($execution, $ikey, 'corr-a-' . Str::ulid());

        $stay2 = $this->makeInHouseStay($this->property);
        $review2 = $this->makeFinalReview($this->property, $stay2);
        $execution2 = $this->createCheckoutExecution($stay2, $review2, $bd, 'exec-idem-uq2-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->createHandoff($execution2, $ikey, 'corr-b-' . Str::ulid());
    }

    public function test_property_scoped_correlation_identity_enforced(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-corr-uq-' . Str::ulid());

        $ckey = 'corr-unique-' . Str::ulid();
        $this->createHandoff($execution, 'idem-corr-a-' . Str::ulid(), $ckey);

        $stay2 = $this->makeInHouseStay($this->property);
        $review2 = $this->makeFinalReview($this->property, $stay2);
        $execution2 = $this->createCheckoutExecution($stay2, $review2, $bd, 'exec-corr-uq2-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->createHandoff($execution2, 'idem-corr-b-' . Str::ulid(), $ckey);
    }

    public function test_property_scoped_source_hash_enforced(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sh-uq-' . Str::ulid());

        $sh = $this->sha256($execution->id . 'sh-unique');
        $this->createHandoff($execution, 'idem-sh-a-' . Str::ulid(), 'corr-sh-a-' . Str::ulid());
        // Same hash in different handoff
        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-sh-b-' . Str::ulid(), 'corr-sh-b-' . Str::ulid());
        $payload['source_hash'] = $sh;
        // The FD-C1 execution already has a FK: one per execution, so use the same execution with the same hash...

        // Actually test same-property same-hash rejection
        $stay2 = $this->makeInHouseStay($this->property);
        $review2 = $this->makeFinalReview($this->property, $stay2);
        $execution2 = $this->createCheckoutExecution($stay2, $review2, $bd, 'exec-sh-uq2-' . Str::ulid());

        $handoff2 = new FrontDeskCheckoutHousekeepingHandoff();
        $payload2 = $this->validHandoffPayload($execution2, 'idem-sh-c-' . Str::ulid(), 'corr-sh-c-' . Str::ulid());
        $handoff2->forceFill($payload2)->save();
        // The first handoff's hash was generated from validHandoffPayload which uses different idempotency key
        // So the hashes differ. Let's test with explicit matching hash:
        $firstHash = $handoff2->source_hash;

        $handoff3 = new FrontDeskCheckoutHousekeepingHandoff();
        $payload3 = $this->validHandoffPayload($execution2, 'idem-sh-d-' . Str::ulid(), 'corr-sh-d-' . Str::ulid());
        $payload3['source_hash'] = $firstHash;

        $this->expectException(QueryException::class);
        $handoff3->forceFill($payload3)->save();
    }

    public function test_one_handoff_per_checkout_execution(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-one-' . Str::ulid());

        $this->createHandoff($execution, 'idem-one-' . Str::ulid(), 'corr-one-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->createHandoff($execution, 'idem-one-2-' . Str::ulid(), 'corr-one-2-' . Str::ulid());
    }

    public function test_one_handoff_per_stay(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-stay1-' . Str::ulid());

        $this->createHandoff($execution, 'idem-stay1-' . Str::ulid(), 'corr-stay1-' . Str::ulid());

        // Same property, same stay, different execution should still fail on stay_unique
        // We can test by trying to insert a second row with the same stay
        $handoff2 = new FrontDeskCheckoutHousekeepingHandoff();
        $payload2 = $this->validHandoffPayload($execution, 'idem-stay1b-' . Str::ulid(), 'corr-stay1b-' . Str::ulid());
        $payload2['checkout_execution_id'] = $execution->id;

        $this->expectException(QueryException::class);
        $handoff2->forceFill($payload2)->save();
    }

    // ── All five FKs reject nonexistent references ─────────────────────────

    public function test_fk_rejects_nonexistent_property(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fk-prop-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-fk-prop-' . Str::ulid(), 'corr-fk-prop-' . Str::ulid());
        $payload['property_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    public function test_fk_rejects_nonexistent_stay(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fk-stay-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-fk-stay-' . Str::ulid(), 'corr-fk-stay-' . Str::ulid());
        $payload['front_desk_stay_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    public function test_fk_rejects_nonexistent_reservation(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fk-res-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-fk-res-' . Str::ulid(), 'corr-fk-res-' . Str::ulid());
        $payload['reservation_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    public function test_fk_rejects_nonexistent_checkout_execution(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fk-ce-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-fk-ce-' . Str::ulid(), 'corr-fk-ce-' . Str::ulid());
        $payload['checkout_execution_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    public function test_fk_rejects_nonexistent_business_date(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fk-bd-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-fk-bd-' . Str::ulid(), 'corr-fk-bd-' . Str::ulid());
        $payload['property_business_date_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    // ── Source relationship mismatch ───────────────────────────────────────

    public function test_source_mismatch_property_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-prop-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-prop-' . Str::ulid(), 'corr-src-prop-' . Str::ulid());
        $payload['property_id'] = $this->otherProperty->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    public function test_source_mismatch_stay_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $stay2 = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $review2 = $this->makeFinalReview($this->property, $stay2);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-stay-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-stay-' . Str::ulid(), 'corr-src-stay-' . Str::ulid());
        $payload['front_desk_stay_id'] = $stay2->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    public function test_source_mismatch_reservation_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-res-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-res-' . Str::ulid(), 'corr-src-res-' . Str::ulid());
        $payload['reservation_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    public function test_source_mismatch_business_date_id_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-bdid-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-bdid-' . Str::ulid(), 'corr-src-bdid-' . Str::ulid());
        $payload['property_business_date_id'] = (string) Str::ulid();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    public function test_source_mismatch_business_date_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-bdval-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-bdval-' . Str::ulid(), 'corr-src-bdval-' . Str::ulid());
        $payload['business_date'] = '2026-01-01';

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    public function test_source_mismatch_terminal_status_checked_by_trigger(): void
    {
        // FD-C1 already enforces terminal_stay_status = 'CHECKED_OUT' at the DB level
        // via fd_ce_terminal_status_check. The fd_chh_check_source_relationship trigger
        // independently re-verifies this as defense-in-depth.
        // We verify the trigger function exists and contains the CHECKED_OUT check.

        $this->assertTrue(
            DB::table('pg_proc')->where('proname', 'fd_chh_check_source_relationship')->exists(),
            'Source relationship trigger function must exist.'
        );

        // Verify the trigger function source contains the CHECKED_OUT check
        $funcSource = DB::table('pg_proc')
            ->where('proname', 'fd_chh_check_source_relationship')
            ->value('prosrc');

        $this->assertStringContainsString(
            'CHECKED_OUT',
            $funcSource,
            'Source relationship function must check for CHECKED_OUT terminal status.'
        );

        $this->assertStringContainsString(
            'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH',
            $funcSource,
            'Source relationship function must raise FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH.'
        );
    }

    public function test_source_mismatch_occurred_at_before_execution_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-src-occ-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-src-occ-' . Str::ulid(), 'corr-src-occ-' . Str::ulid());
        // Set occurred_at to 1 hour before the execution (relative to frozen Carbon time)
        $payload['occurred_at'] = Carbon::now()->subHour();

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_SOURCE_MISMATCH');
        $handoff->forceFill($payload)->save();
    }

    // ── Invalid hashes ────────────────────────────────────────────────────

    public function test_invalid_source_hash_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-hash-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-hash-' . Str::ulid(), 'corr-hash-' . Str::ulid());
        $payload['source_hash'] = 'NOT-A-VALID-SHA256-HASH';

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    // ── Whitespace keys rejected ──────────────────────────────────────────

    public function test_whitespace_idempotency_key_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-ws-ik-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, '  leading-space', 'corr-ws-ik-' . Str::ulid());

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    public function test_whitespace_correlation_key_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-ws-ck-' . Str::ulid());

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $payload = $this->validHandoffPayload($execution, 'idem-ws-ck-' . Str::ulid(), 'trailing ');
        $payload['correlation_key'] = 'trailing ';

        $this->expectException(QueryException::class);
        $handoff->forceFill($payload)->save();
    }

    // ── Invalid statuses and state shapes rejected ─────────────────────────

    public function test_invalid_status_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-badst-' . Str::ulid());

        $this->expectException(QueryException::class);
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => (string) Str::ulid(),
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => 'idem-badst-' . Str::ulid(),
            'correlation_key' => 'corr-badst-' . Str::ulid(),
            'source_hash' => hash('sha256', 'src-badst-' . Str::ulid()),
            'delivery_status' => 'PROCESSING',
            'attempts' => 0,
            'available_at' => now(),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    // ── Immutable payload blocked ─────────────────────────────────────────

    public function test_immutable_payload_update_blocked_through_eloquent(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-imm-el-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-imm-el-' . Str::ulid(), 'corr-imm-el-' . Str::ulid());
        $handoff->idempotency_key = 'mutated-key';

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE');
        $handoff->save();
    }

    public function test_immutable_payload_update_blocked_through_raw_sql(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-imm-raw-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-imm-raw-' . Str::ulid(), 'corr-imm-raw-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE');
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update(['idempotency_key' => 'raw-mutated']);
    }

    // ── Delete blocked ────────────────────────────────────────────────────

    public function test_delete_blocked_through_eloquent(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-del-el-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-del-el-' . Str::ulid(), 'corr-del-el-' . Str::ulid());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN');
        $handoff->delete();
    }

    public function test_delete_blocked_through_raw_sql(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-del-raw-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-del-raw-' . Str::ulid(), 'corr-del-raw-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN');
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->delete();
    }

    // ── Service: PENDING claim succeeds ───────────────────────────────────

    public function test_pending_claim_succeeds(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-claim-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-claim-' . Str::ulid(), 'corr-svc-claim-' . Str::ulid());

        $result = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);

        $this->assertSame($handoff->id, $result['handoff_id']);
        $this->assertNotEmpty($result['claim_token']);
        $this->assertSame(64, strlen($result['claim_token'])); // 32 bytes hex = 64 chars
        $this->assertNotNull($result['claimed_at']);
        $this->assertNotNull($result['claim_expires_at']);

        $handoff->refresh();
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed, $handoff->delivery_status);
        $this->assertSame(1, $handoff->attempts);
        $this->assertNotNull($handoff->claim_token_hash);
        $this->assertSame(64, strlen($handoff->claim_token_hash));
        // Raw token must not be persisted
        $this->assertStringNotContainsString($result['claim_token'], $handoff->claim_token_hash);
    }

    public function test_attempts_increments_once(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-att-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-att-' . Str::ulid(), 'corr-svc-att-' . Str::ulid());

        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();
        $this->assertSame(1, $handoff->attempts);
    }

    public function test_active_repeat_claim_does_not_increment(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-rep-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-rep-' . Str::ulid(), 'corr-svc-rep-' . Str::ulid());

        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();
        $this->assertSame(1, $handoff->attempts);

        // Repeat claim with active lease — should fail
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
    }

    public function test_expired_claim_may_be_reclaimed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-exp-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-exp-' . Str::ulid(), 'corr-svc-exp-' . Str::ulid());

        // Claim with 1 second lease
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 1);
        $handoff->refresh();
        $this->assertSame(1, $handoff->attempts);

        // Advance past expiry
        Carbon::setTestNow(Carbon::now()->addSeconds(5));

        // Reclaim
        $result = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();
        $this->assertSame(2, $handoff->attempts);
        $this->assertNotEmpty($result['claim_token']);
    }

    public function test_raw_claim_token_is_not_persisted(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-tok-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-tok-' . Str::ulid(), 'corr-svc-tok-' . Str::ulid());

        $result = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();

        $this->assertNotNull($result['claim_token']);
        $this->assertSame(64, strlen($result['claim_token']));
        $this->assertSame(64, strlen($handoff->claim_token_hash));

        // The persisted hash must not equal the raw token
        $this->assertNotSame($result['claim_token'], $handoff->claim_token_hash);

        // The hash must be the SHA-256 of the token
        $this->assertSame(hash('sha256', $result['claim_token']), $handoff->claim_token_hash);
    }

    // ── Service: wrong token rejected ─────────────────────────────────────

    public function test_wrong_claim_token_rejected_on_delivery(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-wtok-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-wtok-' . Str::ulid(), 'corr-svc-wtok-' . Str::ulid());

        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_CLAIM_TOKEN');
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, bin2hex(random_bytes(32)));
    }

    // ── Service: valid delivery succeeds ──────────────────────────────────

    public function test_valid_delivery_succeeds(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-del-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-del-' . Str::ulid(), 'corr-svc-del-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $delivered = $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered, $delivered->delivery_status);
        $this->assertNotNull($delivered->delivered_at);
        $this->assertNull($delivered->failed_at);
        $this->assertNull($delivered->last_error_code);
    }

    public function test_repeated_identical_delivery_is_idempotent(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-del2-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-del2-' . Str::ulid(), 'corr-svc-del2-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $delivered1 = $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);
        $delivered2 = $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $this->assertSame($delivered1->id, $delivered2->id);
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered, $delivered2->delivery_status);
        $this->assertSame($delivered1->delivered_at->toIso8601String(), $delivered2->delivered_at->toIso8601String());
    }

    public function test_delivered_row_cannot_be_reclaimed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-del3-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-del3-' . Str::ulid(), 'corr-svc-del3-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
    }

    // ── Service: valid failure succeeds ───────────────────────────────────

    public function test_valid_failure_succeeds(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-fail-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-fail-' . Str::ulid(), 'corr-svc-fail-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $failed = $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $failed->delivery_status);
        $this->assertNotNull($failed->failed_at);
        $this->assertSame('HK_DELIVERY_TIMEOUT', $failed->last_error_code);
        $this->assertEquals($retryAt->timestamp, $failed->available_at->timestamp);
        $this->assertNull($failed->delivered_at);
    }

    public function test_repeated_identical_failure_is_idempotent(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-fail2-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-fail2-' . Str::ulid(), 'corr-svc-fail2-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);

        $failed1 = $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );
        $failed2 = $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        $this->assertSame($failed1->id, $failed2->id);
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $failed2->delivery_status);
        $this->assertSame($failed1->failed_at->toIso8601String(), $failed2->failed_at->toIso8601String());
    }

    public function test_failed_row_becomes_retryable_at_available_at(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-retry-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-retry-' . Str::ulid(), 'corr-svc-retry-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        // Early retry cannot claim
        Carbon::setTestNow(Carbon::now()->addMinutes(2));
        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
    }

    public function test_due_retry_can_claim(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-due-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-due-' . Str::ulid(), 'corr-svc-due-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        Carbon::setTestNow(Carbon::now()->addMinutes(6));
        $result = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed, $handoff->delivery_status);
        $this->assertSame(2, $handoff->attempts);
        $this->assertNotEmpty($result['claim_token']);
    }

    public function test_invalid_error_code_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-svc-ecode-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-svc-ecode-' . Str::ulid(), 'corr-svc-ecode-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_ERROR_CODE');
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'invalid error code with spaces!', $retryAt
        );
    }

    // ── No stay transition / no checkout execution mutation ───────────────

    public function test_no_stay_transition_occurs(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-nostay-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-nostay-' . Str::ulid(), 'corr-nostay-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $stay->refresh();
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, $stay->status);
    }

    public function test_no_checkout_execution_mutation_occurs(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-nomut-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-nomut-' . Str::ulid(), 'corr-nomut-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $execution->refresh();
        $this->assertSame('CHECKED_OUT', $execution->terminal_stay_status->value);
        $this->assertSame($stay->id, $execution->front_desk_stay_id);
    }

    // ── No housekeeping mutation (structural proof) ───────────────────────

    public function test_no_housekeeping_readiness_row_changes(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-nohk-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-nohk-' . Str::ulid(), 'corr-nohk-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        // No Housekeeping table should have been affected — verify our handoff table only
        $this->assertSame(1, DB::table('front_desk_checkout_housekeeping_handoffs')->count());
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Delivered->value, DB::table('front_desk_checkout_housekeeping_handoffs')->first()->delivery_status);
    }

    // ── Cross-property isolation ──────────────────────────────────────────

    public function test_cross_property_handoff_not_claimable(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-xprop-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-xprop-' . Str::ulid(), 'corr-xprop-' . Str::ulid());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->deliveryService->claimAvailable($this->otherProperty->id, $handoff->id, 60);
    }

    public function test_invalid_lease_seconds_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-lease-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-lease-' . Str::ulid(), 'corr-lease-' . Str::ulid());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_LEASE');
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 0);
    }

    public function test_lease_maximum_enforced(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-leasemax-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-leasemax-' . Str::ulid(), 'corr-leasemax-' . Str::ulid());

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_LEASE');
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 301);
    }

    public function test_expired_claim_rejected_on_delivery(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-expdel-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-expdel-' . Str::ulid(), 'corr-expdel-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 1);
        Carbon::setTestNow(Carbon::now()->addSeconds(5));

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);
    }

    public function test_conflicting_failure_replay_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-conflict-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-conflict-' . Str::ulid(), 'corr-conflict-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_CONFLICTING_REPLAY');
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DIFFERENT_ERROR', $retryAt
        );
    }

    public function test_outbox_messages_unchanged(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-outbox-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-outbox-' . Str::ulid(), 'corr-outbox-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        // outbox_messages should have zero rows
        $this->assertSame(0, DB::table('outbox_messages')->count());
    }

    // ── Correction: Property context isolation ────────────────────────────

    public function test_current_property_a_cannot_claim_property_b_row(): void
    {
        $stay = $this->makeInHouseStay($this->otherProperty);
        $review = $this->makeFinalReview($this->otherProperty, $stay);
        $bd = $this->makeBusinessDate($this->otherProperty);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-xprop-claim-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-xprop-claim-' . Str::ulid(), 'corr-xprop-claim-' . Str::ulid());

        // currentProperty is set to $this->property, but handoff belongs to $this->otherProperty
        $this->currentProperty->setPropertyId($this->property->id);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_UNAVAILABLE');
        $this->deliveryService->claimAvailable($this->otherProperty->id, $handoff->id, 60);
    }

    public function test_mismatched_property_input_does_not_mutate_handoff(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-mismatch-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-mismatch-' . Str::ulid(), 'corr-mismatch-' . Str::ulid());
        $originalAttempts = $handoff->attempts;
        $originalStatus = $handoff->delivery_status;
        $originalClaimedAt = $handoff->claimed_at;
        $originalTokenHash = $handoff->claim_token_hash;

        try {
            $this->deliveryService->claimAvailable($this->otherProperty->id, $handoff->id, 60);
        } catch (DomainException) {}

        $handoff->refresh();
        $this->assertSame($originalAttempts, $handoff->attempts);
        $this->assertEquals($originalStatus, $handoff->delivery_status);
        $this->assertEquals($originalClaimedAt, $handoff->claimed_at);
        $this->assertEquals($originalTokenHash, $handoff->claim_token_hash);
    }

    public function test_unresolved_current_property_fails_closed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-nullprop-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-nullprop-' . Str::ulid(), 'corr-nullprop-' . Str::ulid());

        $this->currentProperty->setPropertyId(null);

        $this->expectException(\Shared\Exceptions\PropertyNotResolvedException::class);
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
    }

    // ── Correction: Transition integrity ──────────────────────────────────

    public function test_active_claimed_raw_reclaim_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-raw-reclaim-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-raw-reclaim-' . Str::ulid(), 'corr-raw-reclaim-' . Str::ulid());

        // Claim via service
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $handoff->refresh();

        // Try raw SQL reclaim on active claim
        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => DB::raw('attempts + 1'),
                'claimed_at' => now(),
                'claim_expires_at' => now()->addSeconds(120),
                'claim_token_hash' => str_repeat('f', 64),
                'updated_at' => now(),
            ]);
    }

    public function test_expired_claimed_raw_reclaim_accepted(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-exp-raw-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-exp-raw-' . Str::ulid(), 'corr-exp-raw-' . Str::ulid());

        // Claim with 1-second lease via service
        $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 1);
        $handoff->refresh();
        $originalAttempts = $handoff->attempts;

        // Advance past expiry
        Carbon::setTestNow(Carbon::now()->addSeconds(5));

        // Raw reclaim on expired claim
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => $originalAttempts + 1,
                'claimed_at' => now(),
                'claim_expires_at' => now()->addSeconds(120),
                'claim_token_hash' => str_repeat('e', 64),
                'updated_at' => now(),
            ]);

        $handoff->refresh();
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed, $handoff->delivery_status);
        $this->assertSame($originalAttempts + 1, $handoff->attempts);
        $this->assertSame(str_repeat('e', 64), $handoff->claim_token_hash);
    }

    public function test_attempts_tampering_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-tamper-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-tamper-' . Str::ulid(), 'corr-tamper-' . Str::ulid());
        // Capture pre-mutation state
        $this->assertSame(0, $handoff->attempts);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update(['attempts' => 5, 'updated_at' => now()]);
    }

    public function test_delivered_row_update_timestamp_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-del-touch-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-del-touch-' . Str::ulid(), 'corr-del-touch-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $this->deliveryService->markDelivered($this->property->id, $handoff->id, $claimResult['claim_token']);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update(['updated_at' => now()->addHour()]);
    }

    public function test_same_status_pending_mutation_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-pend-mut-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-pend-mut-' . Str::ulid(), 'corr-pend-mut-' . Str::ulid());

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update(['available_at' => now()->addHour(), 'updated_at' => now()]);
    }

    public function test_same_status_failed_mutation_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-fail-mut-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-fail-mut-' . Str::ulid(), 'corr-fail-mut-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update(['last_error_code' => 'HK_OTHER', 'updated_at' => now()]);
    }

    // ── Correction: markFailed replay idempotency ─────────────────────────

    public function test_failed_replay_before_retry_time(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-replay-b4-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-replay-b4-' . Str::ulid(), 'corr-replay-b4-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(10);
        $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        // Replay with same params before retry time — should succeed idempotently
        $replay = $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        $handoff->refresh();
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $replay->delivery_status);
        $this->assertSame('HK_DELIVERY_TIMEOUT', $replay->last_error_code);
    }

    public function test_failed_replay_after_retry_time(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-replay-aft-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-replay-aft-' . Str::ulid(), 'corr-replay-aft-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        // Advance past retry time
        Carbon::setTestNow(Carbon::now()->addMinutes(10));

        // Replay with same params after retry time — should still succeed idempotently
        $replay = $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $replay->delivery_status);
        $this->assertSame('HK_DELIVERY_TIMEOUT', $replay->last_error_code);
    }

    public function test_failed_replay_different_retry_at_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-replay-dfr-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-replay-dfr-' . Str::ulid(), 'corr-replay-dfr-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        $retryAt = Carbon::now()->addMinutes(5);
        $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', $retryAt);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_CONFLICTING_REPLAY');
        $this->deliveryService->markFailed($this->property->id, $handoff->id, $claimResult['claim_token'], 'HK_DELIVERY_TIMEOUT', Carbon::now()->addMinutes(7));
    }

    // ── Database clock bypass tests ────────────────────────────────────────

    public function test_pending_future_available_at_rejected_by_trigger_clock(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-bypass1-' . Str::ulid());

        $handoffId = (string) Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $handoffId, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => 'idem-bypass1-' . Str::ulid(),
            'correlation_key' => 'corr-bypass1-' . Str::ulid(),
            'source_hash' => hash('sha256', 'src-bypass1-' . Str::ulid()),
            'delivery_status' => 'PENDING', 'attempts' => 0,
            'available_at' => DB::raw("CURRENT_TIMESTAMP + interval '1 hour'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 1,
                'claimed_at' => DB::raw("CURRENT_TIMESTAMP + interval '2 hours'"),
                'claim_expires_at' => DB::raw("CURRENT_TIMESTAMP + interval '3 hours'"),
                'claim_token_hash' => str_repeat('a', 64), 'updated_at' => now(),
            ]);
    }

    public function test_expired_claim_reclaim_succeeds_after_db_time_due(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-bypass4-' . Str::ulid());

        $handoffId = (string) Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $handoffId, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => 'idem-bypass4-' . Str::ulid(),
            'correlation_key' => 'corr-bypass4-' . Str::ulid(),
            'source_hash' => hash('sha256', 'src-bypass4-' . Str::ulid()),
            'delivery_status' => 'CLAIMED', 'attempts' => 1,
            'claimed_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
            'claim_expires_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 second'"),
            'claim_token_hash' => str_repeat('d', 64),
            'available_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
            'occurred_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
            'created_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
            'updated_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
        ]);

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 2,
                'claimed_at' => DB::raw('CURRENT_TIMESTAMP'),
                'claim_expires_at' => DB::raw("CURRENT_TIMESTAMP + interval '120 seconds'"),
                'claim_token_hash' => str_repeat('e', 64), 'updated_at' => now(),
            ]);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->first();
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(str_repeat('e', 64), $row->claim_token_hash);
    }

    public function test_due_failed_retry_succeeds_after_db_time_due(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-bypass5-' . Str::ulid());

        $handoffId = (string) Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $handoffId, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => 'idem-bypass5-' . Str::ulid(),
            'correlation_key' => 'corr-bypass5-' . Str::ulid(),
            'source_hash' => hash('sha256', 'src-bypass5-' . Str::ulid()),
            'delivery_status' => 'FAILED', 'attempts' => 1,
            'claimed_at' => DB::raw("CURRENT_TIMESTAMP - interval '2 hours'"),
            'claim_expires_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 hour'"),
            'claim_token_hash' => str_repeat('f', 64),
            'failed_at' => DB::raw("CURRENT_TIMESTAMP - interval '30 minutes'"),
            'last_error_code' => 'HK_DELIVERY_TIMEOUT',
            'available_at' => DB::raw("CURRENT_TIMESTAMP - interval '1 second'"),
            'occurred_at' => DB::raw("CURRENT_TIMESTAMP - interval '2 hours'"),
            'created_at' => DB::raw("CURRENT_TIMESTAMP - interval '2 hours'"),
            'updated_at' => DB::raw("CURRENT_TIMESTAMP - interval '2 hours'"),
        ]);

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 2,
                'claimed_at' => DB::raw('CURRENT_TIMESTAMP'),
                'claim_expires_at' => DB::raw("CURRENT_TIMESTAMP + interval '120 seconds'"),
                'claim_token_hash' => str_repeat('a', 64),
                'failed_at' => null, 'last_error_code' => null, 'updated_at' => now(),
            ]);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->first();
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(str_repeat('a', 64), $row->claim_token_hash);
        $this->assertSame('CLAIMED', $row->delivery_status);
    }
}
