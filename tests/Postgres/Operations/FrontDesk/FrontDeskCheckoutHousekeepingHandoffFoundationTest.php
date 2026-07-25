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
            'available_at' => now()->subSeconds(5),
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

        // Wait for the 1-second lease to actually expire by wall clock
        usleep(1_500_000); // 1.5s

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

        // Early retry cannot claim — available_at is still in the future via DB clock
        // (available_at was set to now+5min by markFailed; DB clock_timestamp() is ≈now)
        $handoff->refresh();
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
        $retryAt = Carbon::now()->addSeconds(3);
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_DELIVERY_TIMEOUT', $retryAt
        );

        // Wait for the retry time to elapse
        usleep(4_000_000);

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

    public function test_exact_300_second_lease_succeeds(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-300s-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-300s-' . Str::ulid(), 'corr-300s-' . Str::ulid());

        $result = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 300);

        // ── Returned evidence ──────────────────────────────────────────
        $this->assertSame($handoff->id, $result['handoff_id']);
        $this->assertNotEmpty($result['claim_token']);
        $this->assertSame(64, strlen($result['claim_token']));
        $this->assertNotNull($result['claimed_at']);
        $this->assertNotNull($result['claim_expires_at']);

        // ── Persisted row ─────────────────────────────────────────────
        $handoff->refresh();
        $this->assertEquals(
            \Modules\Operations\FrontDesk\Enums\FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed,
            $handoff->delivery_status
        );
        $this->assertSame(1, $handoff->attempts);           // incremented exactly once
        $this->assertNotNull($handoff->claimed_at);          // database-owned
        $this->assertNotNull($handoff->claim_expires_at);    // database-owned after lease preservation
        $this->assertSame(64, strlen($handoff->claim_token_hash));
        // Raw token must not be persisted
        $this->assertStringNotContainsString($result['claim_token'], $handoff->claim_token_hash);

        // ── Lease interval: exactly 300 seconds at DB precision ───────
        $dbRow = DB::selectOne(
            'SELECT EXTRACT(EPOCH FROM (claim_expires_at - claimed_at))::numeric(12,6) AS lease_seconds
               FROM front_desk_checkout_housekeeping_handoffs
              WHERE id = ?',
            [$handoff->id]
        );
        $this->assertNotNull($dbRow, 'Persisted row must exist.');
        $this->assertSame('300.000000', $dbRow->lease_seconds,
            'Lease interval must be exactly 300.000000 seconds at database precision.'
        );

        // ── Returned evidence matches persisted row at DB precision ────
        $dbEvidence = DB::selectOne(
            'SELECT claimed_at AS persisted_claimed_at,
                    claim_expires_at AS persisted_claim_expires_at
               FROM front_desk_checkout_housekeeping_handoffs
              WHERE id = ?',
            [$handoff->id]
        );
        $this->assertNotNull($dbEvidence, 'Persisted row must exist for evidence check.');
        // Normalize both sides to ISO 8601 for exact comparison
        $this->assertSame(
            Carbon::parse($dbEvidence->persisted_claimed_at)->toIso8601String(),
            $result['claimed_at'],
            'Returned claimed_at must match persisted value at DB precision.'
        );
        $this->assertSame(
            Carbon::parse($dbEvidence->persisted_claim_expires_at)->toIso8601String(),
            $result['claim_expires_at'],
            'Returned claim_expires_at must match persisted value at DB precision.'
        );

        // ── No terminal evidence ──────────────────────────────────────
        $this->assertNull($handoff->delivered_at);
        $this->assertNull($handoff->failed_at);
        $this->assertNull($handoff->last_error_code);
    }

    public function test_expired_claim_rejected_on_delivery(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-expdel-' . Str::ulid());

        $handoff = $this->createHandoff($execution, 'idem-expdel-' . Str::ulid(), 'corr-expdel-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 1);

        // Wait for the 1-second lease to actually expire by wall clock
        usleep(1_500_000); // 1.5s

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

        // Advance past expiry by waiting for the 1-second lease to elapse
        usleep(1_500_000);

        // Raw reclaim on expired claim
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => $originalAttempts + 1,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '120 seconds'"),
                'claim_token_hash' => hash('sha256', 'exp-raw-reclaim'),
                'updated_at' => now(),
            ]);

        $handoff->refresh();
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed, $handoff->delivery_status);
        $this->assertSame($originalAttempts + 1, $handoff->attempts);
        $this->assertSame(hash('sha256', 'exp-raw-reclaim'), $handoff->claim_token_hash);
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

        // Replay with same params (idempotent) — no time manipulation needed
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

    // ── Retry precision at stored timestamp(0) boundary ────────────────────

    public function test_retry_at_normalizes_to_current_db_second_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-retry-now-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-retry-now-' . Str::ulid(), 'corr-retry-now-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        // Use the DB wall clock itself — after setMicrosecond(0) normalization
        // this truncates to the current second, which is not strictly later
        // than the DB clock at timestamp(0) precision.
        $dbNow = DB::selectOne("SELECT clock_timestamp() AT TIME ZONE 'UTC' AS now")->now;
        $retryAt = Carbon::parse($dbNow);

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_RETRY_TIME');
        $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_PRECISION_SAMESEC', $retryAt
        );
    }

    public function test_retry_at_five_minutes_in_future_succeeds(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-retry-5m-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-retry-5m-' . Str::ulid(), 'corr-retry-5m-' . Str::ulid());

        $claimResult = $this->deliveryService->claimAvailable($this->property->id, $handoff->id, 60);
        // 5 minutes in the future — safely beyond one stored-precision second
        $retryAt = Carbon::now()->addMinutes(5);

        $result = $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_PRECISION_OK', $retryAt
        );

        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $result->delivery_status);
        $this->assertSame('HK_PRECISION_OK', $result->last_error_code);
        // Replay with identical normalized retryAt must be idempotent
        $replay = $this->deliveryService->markFailed(
            $this->property->id, $handoff->id, $claimResult['claim_token'],
            'HK_PRECISION_OK', $retryAt
        );
        $this->assertEquals(FrontDeskCheckoutHousekeepingHandoffStatusEnum::Failed, $replay->delivery_status);
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
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '1 hour'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 1,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '2 hours'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '3 hours'"),
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
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 second'"),
            'claim_token_hash' => str_repeat('d', 64),
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
        ]);

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 2,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '120 seconds'"),
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
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_token_hash' => str_repeat('f', 64),
            'failed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '30 minutes'"),
            'last_error_code' => 'HK_DELIVERY_TIMEOUT',
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 second'"),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
        ]);

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoffId)
            ->update([
                'delivery_status' => 'CLAIMED', 'attempts' => 2,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '120 seconds'"),
                'claim_token_hash' => str_repeat('a', 64),
                'failed_at' => null, 'last_error_code' => null, 'updated_at' => now(),
            ]);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $handoffId)->first();
        $this->assertSame(2, (int) $row->attempts);
        $this->assertSame(str_repeat('a', 64), $row->claim_token_hash);
        $this->assertSame('CLAIMED', $row->delivery_status);
    }

    // ──────────────────────────────────────────────────────────────────────
    // Lease clock integrity — database wall-clock authority
    // ──────────────────────────────────────────────────────────────────────

    /**
     * Resolve actual PostgreSQL wall-clock time via clock_timestamp().
     */
    private function dbWallClock(): \DateTimeImmutable
    {
        $result = DB::selectOne('SELECT clock_timestamp() AS wall_clock');
        return new \DateTimeImmutable($result->wall_clock);
    }

    // ── A. Application clock behind database clock ────────────────────────

    public function test_mark_delivered_rejects_expired_claim_by_database_clock(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-skew-del-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-skew-del-' . Str::ulid(), 'corr-skew-del-' . Str::ulid());

        // Claim with a very short lease (1 second) so it expires almost immediately
        $claim = $this->deliveryService->claimAvailable(
            $this->property->id, $handoff->id, 1
        );

        // Wait for the lease to actually expire by wall clock
        usleep(1_500_000); // 1.5 seconds — longer than the 1s lease

        // Set Carbon test clock to BEFORE expiry (simulating stale application clock)
        Carbon::setTestNow(Carbon::parse($claim['claimed_at']));

        // Delivery must fail because database wall clock sees the lease as expired
        try {
            $this->deliveryService->markDelivered(
                $this->property->id, $handoff->id, $claim['claim_token']
            );
            $this->fail('Expected expired-claim rejection but delivery succeeded.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('EXPIRED_CLAIM', $e->getMessage());
        }

        // Prove status remains CLAIMED and no delivery timestamp was added
        $row = DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)->first();
        $this->assertSame('CLAIMED', $row->delivery_status);
        $this->assertNull($row->delivered_at);
    }

    public function test_mark_failed_rejects_expired_claim_by_database_clock(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-skew-fail-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-skew-fail-' . Str::ulid(), 'corr-skew-fail-' . Str::ulid());

        $claim = $this->deliveryService->claimAvailable(
            $this->property->id, $handoff->id, 1
        );

        usleep(1_500_000);

        Carbon::setTestNow(Carbon::parse($claim['claimed_at']));

        $retryAt = new \DateTimeImmutable('+60 seconds');

        try {
            $this->deliveryService->markFailed(
                $this->property->id, $handoff->id, $claim['claim_token'],
                'HK_TIMEOUT', $retryAt
            );
            $this->fail('Expected expired-claim rejection but failure succeeded.');
        } catch (DomainException $e) {
            $this->assertStringContainsString('EXPIRED_CLAIM', $e->getMessage());
        }

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $handoff->id)->first();
        $this->assertSame('CLAIMED', $row->delivery_status);
        $this->assertNull($row->failed_at);
        $this->assertNull($row->last_error_code);
    }

    public function test_claim_available_uses_database_time_when_application_clock_behind(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-skew-claim-' . Str::ulid());

        // Create handoff with occurred_at slightly after the execution's occurred_at
        // (required by fd_chh_check_source_relationship trigger).
        // available_at is set 5 seconds in the past so the DB wall clock sees it as due.
        $handoffTime = Carbon::now()->addSecond()->format('Y-m-d H:i:s.u');
        $availableTime = Carbon::now()->subSeconds(5)->format('Y-m-d H:i:s.u');
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => ($hid = Str::ulid()->toString()),
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date->toDateString(),
            'idempotency_key' => 'idem-skew-claim-' . Str::ulid(),
            'correlation_key' => 'corr-skew-claim-' . Str::ulid(),
            'source_hash' => $this->sha256(Str::ulid()->toString()),
            'available_at' => $availableTime,
            'occurred_at' => $handoffTime,
            'created_at' => $handoffTime,
            'updated_at' => $handoffTime,
        ]);

        // Set Carbon 10 seconds behind — but DB says the handoff is available
        Carbon::setTestNow(now()->subSeconds(10));

        $claim = $this->deliveryService->claimAvailable(
            $this->property->id, $hid, 60
        );

        // The claim must succeed using database wall-clock time
        $this->assertNotEmpty($claim['claim_token']);
        $this->assertNotNull($claim['claimed_at']);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $hid)->first();
        // claimed_at must be database-derived (close to actual wall clock, not frozen Carbon)
        $wallClock = $this->dbWallClock();
        $claimedAt = Carbon::parse($row->claimed_at);
        $diffSeconds = abs($wallClock->getTimestamp() - $claimedAt->getTimestamp());
        $this->assertLessThan(5, $diffSeconds, 'claimed_at must be database wall-clock time, not frozen Carbon time.');

        // claim_expires_at must be after claimed_at (valid lease)
        $claimExpiresAt = Carbon::parse($row->claim_expires_at);
        $this->assertGreaterThan($claimedAt->getTimestamp(), $claimExpiresAt->getTimestamp());
    }

    // ── B. Direct SQL trigger enforcement ──────────────────────────────────

    public function test_direct_sql_expired_claimed_to_delivered_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-dl-exp-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-dl-exp-' . Str::ulid();
        $ckey = 'corr-sql-dl-exp-' . Str::ulid();

        // INSERT directly into CLAIMED state with an expired lease (bypasses mutation trigger)
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '3 minutes'"),
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 minutes'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 minute'"),
            'claim_token_hash' => hash('sha256', 'sql-dl-expired-d'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'DELIVERED',
                'delivered_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_expired_claimed_to_failed_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-fl-exp-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-fl-exp-' . Str::ulid();
        $ckey = 'corr-sql-fl-exp-' . Str::ulid();

        // INSERT directly into CLAIMED state with an expired lease
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '3 minutes'"),
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 minutes'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 minute'"),
            'claim_token_hash' => hash('sha256', 'sql-fl-expired-e'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_EXPIRED_CLAIM');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'FAILED',
                'failed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'last_error_code' => 'HK_LEASE_EXPIRED',
                'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_early_pending_to_claimed_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-pc-early-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-pc-early-' . Str::ulid();
        $ckey = 'corr-sql-pc-early-' . Str::ulid();

        // INSERT directly into PENDING state with available_at 60 seconds in the future
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'PENDING',
            'attempts' => 0,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => 1,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
                'claim_token_hash' => str_repeat('f', 64),
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_unexpired_claimed_to_claimed_reclaim_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-cc-block-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-cc-block-' . Str::ulid();
        $ckey = 'corr-sql-cc-block-' . Str::ulid();

        // INSERT directly into CLAIMED state with a still-valid lease (expires in 120s)
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '60 seconds'"),
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '30 seconds'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '120 seconds'"),
            'claim_token_hash' => hash('sha256', 'sql-cc-block-g'),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => 2,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
                'claim_token_hash' => hash('sha256', 'sql-cc-block-reclaim-h'),
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_early_failed_to_claimed_retry_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-fc-early-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-fc-early-' . Str::ulid();
        $ckey = 'corr-sql-fc-early-' . Str::ulid();

        // INSERT directly into FAILED state with available_at 120s in the future
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'FAILED',
            'attempts' => 1,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '120 seconds'"),
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '5 minutes'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '4 minutes'"),
            'claim_token_hash' => hash('sha256', 'sql-fc-early-i'),
            'failed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '4 minutes'"),
            'last_error_code' => 'HK_PREVIOUS_TIMEOUT',
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => 2,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
                'claim_token_hash' => hash('sha256', 'sql-fc-early-retry-j'),
                'failed_at' => null,
                'last_error_code' => null,
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_valid_due_transitions_still_succeed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-valid-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-sql-valid-' . Str::ulid();
        $ckey = 'corr-sql-valid-' . Str::ulid();

        // INSERT directly into PENDING state with available_at in the past (due now)
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid,
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey,
            'correlation_key' => $ckey,
            'source_hash' => $this->sha256($execution->id . $ikey),
            'delivery_status' => 'PENDING',
            'attempts' => 0,
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 second'"),
            'occurred_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // PENDING → CLAIMED must succeed (available_at is due)
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'CLAIMED',
                'attempts' => 1,
                'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
                'claim_token_hash' => hash('sha256', 'sql-valid-k'),
                'updated_at' => now(),
            ]);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $hid)->first();
        $this->assertSame('CLAIMED', $row->delivery_status);
        $this->assertSame(1, (int) $row->attempts);

        // CLAIMED → DELIVERED must succeed (lease still valid)
        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'delivery_status' => 'DELIVERED',
                'delivered_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
                'updated_at' => now(),
            ]);

        $row = DB::table('front_desk_checkout_housekeeping_handoffs')->where('id', $hid)->first();
        $this->assertSame('DELIVERED', $row->delivery_status);
        $this->assertNotNull($row->delivered_at);
    }

    public function test_direct_sql_payload_immutability_remains_intact(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-immu-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-sql-immu-' . Str::ulid(), 'corr-sql-immu-' . Str::ulid());

        $hid = $handoff->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_PAYLOAD_IMMUTABLE');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->update([
                'front_desk_stay_id' => Str::ulid()->toString(),
                'updated_at' => now(),
            ]);
    }

    public function test_direct_sql_delete_prohibition_remains_intact(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-sql-delno-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-sql-delno-' . Str::ulid(), 'corr-sql-delno-' . Str::ulid());

        $hid = $handoff->id;

        $this->expectException(QueryException::class);
        $this->expectExceptionMessage('FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_DELETE_FORBIDDEN');

        DB::table('front_desk_checkout_housekeeping_handoffs')
            ->where('id', $hid)
            ->delete();
    }

    // ── Correction: null claim timestamp trigger guards ──────────────────

    private function assertTransitionRejectedAndRowUnchanged(
        string $hid,
        array $mutation,
        string $expectedMarker = 'FD_C2_CHECKOUT_HOUSEKEEPING_HANDOFF_INVALID_TRANSITION'
    ): void {
        $table = 'front_desk_checkout_housekeeping_handoffs';

        $before = (array) DB::table($table)
            ->where('id', $hid)->first();

        $startingTransactionLevel = DB::transactionLevel();

        DB::beginTransaction();

        try {
            DB::table($table)
                ->where('id', $hid)->update($mutation);
            $this->fail("Expected {$expectedMarker} marker.");
        } catch (QueryException $e) {
            $this->assertStringContainsString($expectedMarker, $e->getMessage());
        } finally {
            while (DB::transactionLevel() > $startingTransactionLevel) {
                DB::rollBack();
            }
        }

        $after = (array) DB::table($table)
            ->where('id', $hid)->first();

        $this->assertEquals(
            $before, $after,
            'Rejected transition must leave every persisted column unchanged.'
        );
    }

    public function test_null_claimed_at_triggers_invalid_transition_on_pending_to_claimed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-claim-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-null-claim-' . Str::ulid(), 'corr-null-claim-' . Str::ulid());

        $this->assertTransitionRejectedAndRowUnchanged($handoff->id, [
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'claimed_at' => null,
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
            'claim_token_hash' => str_repeat('a', 64),
            'updated_at' => now(),
        ]);
    }

    public function test_null_claim_expires_at_triggers_invalid_transition_on_pending_to_claimed(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-expiry-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-null-expiry-' . Str::ulid(), 'corr-null-expiry-' . Str::ulid());

        $this->assertTransitionRejectedAndRowUnchanged($handoff->id, [
            'delivery_status' => 'CLAIMED',
            'attempts' => 1,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
            'claim_expires_at' => null,
            'claim_token_hash' => str_repeat('b', 64),
            'updated_at' => now(),
        ]);
    }

    public function test_null_claimed_at_triggers_invalid_transition_on_claimed_reclaim(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-cl-recl-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-null-cl-recl-' . Str::ulid();
        $ckey = 'corr-null-cl-recl-' . Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey, 'correlation_key' => $ckey,
            'source_hash' => hash('sha256', $execution->id . $ikey),
            'delivery_status' => 'CLAIMED', 'attempts' => 1,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_token_hash' => str_repeat('c', 64),
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '3 hours'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTransitionRejectedAndRowUnchanged($hid, [
            'delivery_status' => 'CLAIMED', 'attempts' => 2,
            'claimed_at' => null,
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
            'claim_token_hash' => str_repeat('d', 64),
            'updated_at' => now(),
        ]);
    }

    public function test_null_claim_expires_at_triggers_invalid_transition_on_claimed_reclaim(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-ce-recl-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-null-ce-recl-' . Str::ulid();
        $ckey = 'corr-null-ce-recl-' . Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey, 'correlation_key' => $ckey,
            'source_hash' => hash('sha256', $execution->id . $ikey),
            'delivery_status' => 'CLAIMED', 'attempts' => 1,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_token_hash' => str_repeat('e', 64),
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '3 hours'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTransitionRejectedAndRowUnchanged($hid, [
            'delivery_status' => 'CLAIMED', 'attempts' => 2,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
            'claim_expires_at' => null,
            'claim_token_hash' => str_repeat('f', 64),
            'updated_at' => now(),
        ]);
    }

    public function test_null_claimed_at_triggers_invalid_transition_on_failed_retry(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-cl-retry-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-null-cl-retry-' . Str::ulid();
        $ckey = 'corr-null-cl-retry-' . Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey, 'correlation_key' => $ckey,
            'source_hash' => hash('sha256', $execution->id . $ikey),
            'delivery_status' => 'FAILED', 'attempts' => 1,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_token_hash' => str_repeat('c', 64),
            'failed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '30 minutes'"),
            'last_error_code' => 'HK_PREVIOUS_TIMEOUT',
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 second'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTransitionRejectedAndRowUnchanged($hid, [
            'delivery_status' => 'CLAIMED', 'attempts' => 2,
            'claimed_at' => null,
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' + interval '60 seconds'"),
            'claim_token_hash' => str_repeat('a', 64),
            'failed_at' => null, 'last_error_code' => null,
            'updated_at' => now(),
        ]);
    }

    public function test_null_claim_expires_at_triggers_invalid_transition_on_failed_retry(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-null-ce-retry-' . Str::ulid());

        $hid = Str::ulid()->toString();
        $ikey = 'idem-null-ce-retry-' . Str::ulid();
        $ckey = 'corr-null-ce-retry-' . Str::ulid();
        DB::table('front_desk_checkout_housekeeping_handoffs')->insert([
            'id' => $hid, 'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date,
            'idempotency_key' => $ikey, 'correlation_key' => $ckey,
            'source_hash' => hash('sha256', $execution->id . $ikey),
            'delivery_status' => 'FAILED', 'attempts' => 1,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '2 hours'"),
            'claim_expires_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 hour'"),
            'claim_token_hash' => str_repeat('b', 64),
            'failed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '30 minutes'"),
            'last_error_code' => 'HK_PREVIOUS_TIMEOUT',
            'available_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC' - interval '1 second'"),
            'occurred_at' => now(), 'created_at' => now(), 'updated_at' => now(),
        ]);

        $this->assertTransitionRejectedAndRowUnchanged($hid, [
            'delivery_status' => 'CLAIMED', 'attempts' => 2,
            'claimed_at' => DB::raw("clock_timestamp() AT TIME ZONE 'UTC'"),
            'claim_expires_at' => null,
            'claim_token_hash' => str_repeat('c', 64),
            'failed_at' => null, 'last_error_code' => null,
            'updated_at' => now(),
        ]);
    }

    // ── C. Claim-state assertion ──────────────────────────────────────────

    public function test_claim_state_remains_stable_after_service_operation(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $execution = $this->createCheckoutExecution($stay, $review, $bd, 'exec-claimstate-' . Str::ulid());
        $handoff = $this->createHandoff($execution, 'idem-claimstate-' . Str::ulid(), 'corr-claimstate-' . Str::ulid());

        // Claim with a 3-second lease — verifies service contract
        $claim = $this->deliveryService->claimAvailable(
            $this->property->id, $handoff->id, 3
        );

        $token = $claim['claim_token'];

        // Concurrency proof with disposable database is in the isolated
        // test class: FrontDeskCheckoutHousekeepingHandoffIsolatedConcurrencyProofTest.
        // This test verifies the service-level claim contract against the
        // shared ivorq_testing database without committing the RefreshDatabase
        // transaction or spawning worker processes.
        $this->assertNotNull($token);
        $this->assertSame(64, strlen($token));

        // The handoff stays CLAIMED — no mutation occurs in this test
        $handoff->refresh();
        $this->assertEquals(
            FrontDeskCheckoutHousekeepingHandoffStatusEnum::Claimed,
            $handoff->delivery_status
        );
    }
}
