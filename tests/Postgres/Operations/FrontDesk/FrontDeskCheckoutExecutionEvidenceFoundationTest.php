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
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Tests\PostgresTestCase;

class FrontDeskCheckoutExecutionEvidenceFoundationTest extends PostgresTestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Property $otherProperty;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        Carbon::setTestNow(Carbon::parse('2026-07-23 12:00:00'));

        $this->company = Company::create([
            'name' => 'FD-C1 Foundation Co',
            'slug' => 'fd-c1-fco-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FD-C1 Property',
            'slug' => 'fd-c1-prop-' . Str::lower(Str::random(6)),
            'code' => 'FD1P' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->otherProperty = Property::create([
            'company_id' => $this->company->id,
            'name' => 'FD-C1 Other',
            'slug' => 'fd-c1-other-' . Str::lower(Str::random(6)),
            'code' => 'FD1O' . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);

        $this->actor = User::create([
            'name' => 'FD-C1 Actor',
            'email' => 'fd-c1-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    // ── Helpers ────────────────────────────────────────────────────────────

    private function makeGuest(Property $prop): Guest
    {
        return Guest::create([
            'property_id' => $prop->id,
            'guest_code' => 'G-' . Str::upper(Str::random(6)),
            'full_name' => 'FD-C1 Guest ' . Str::random(4),
            'guest_type' => 'individual',
        ]);
    }

    private function makeReservation(Property $prop): Reservation
    {
        $guest = $this->makeGuest($prop);

        return Reservation::create([
            'property_id' => $prop->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'FD-C1-R-' . Str::upper(Str::random(6)),
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
            'idempotency_key' => 'dcfr-fdc1-' . Str::ulid(),
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
     * @return array<string, mixed>
     */
    private function validEvidencePayload(FrontDeskStay $stay, FrontDeskDepartureCheckoutFinalReview $review, PropertyBusinessDate $bd, string $idempotencyKey): array
    {
        $naFp = $this->sha256('na-attestation-' . $stay->id);
        $pmsFp = $this->sha256('pms-attestation-' . $stay->id);
        $gcFp = $this->sha256('gc-attestation-' . $stay->id);

        return [
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
        ];
    }

    private function createEvidence(FrontDeskStay $stay, FrontDeskDepartureCheckoutFinalReview $review, PropertyBusinessDate $bd, string $idempotencyKey): FrontDeskCheckoutExecution
    {
        $e = new FrontDeskCheckoutExecution();
        $e->forceFill($this->validEvidencePayload($stay, $review, $bd, $idempotencyKey))->save();
        return $e->fresh();
    }

    // ── Tests ──────────────────────────────────────────────────────────────

    public function test_enum_has_checked_out_terminal_case_only(): void
    {
        $cases = FrontDeskStayStatusEnum::cases();

        $terminalCases = array_filter($cases, fn($c) => !in_array($c->value, [
            'ARRIVAL_READY', 'ROOM_ASSIGNED', 'CHECK_IN_CONFIRMATION_PENDING', 'IN_HOUSE',
        ]));

        $this->assertCount(1, $terminalCases, 'Exactly one terminal case must exist.');
        $terminal = array_values($terminalCases)[0];
        $this->assertSame('CHECKED_OUT', $terminal->value);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $terminal);

        // No DEPARTED alias
        $this->assertFalse(
            in_array('DEPARTED', array_map(fn($c) => $c->value, $cases)),
            'DEPARTED alias must not exist.'
        );
        // No CLOSED alias
        $this->assertFalse(
            in_array('CLOSED', array_map(fn($c) => $c->value, $cases)),
            'CLOSED alias must not exist.'
        );
    }

    public function test_model_table_name_is_exact(): void
    {
        $model = new FrontDeskCheckoutExecution();
        $this->assertSame('front_desk_checkout_executions', $model->getTable());
    }

    public function test_model_has_no_updated_at(): void
    {
        $this->assertNull((new FrontDeskCheckoutExecution())->getUpdatedAtColumn());
    }

    public function test_model_casts_are_exact(): void
    {
        $model = new FrontDeskCheckoutExecution();
        $casts = $model->getCasts();

        $this->assertArrayHasKey('terminal_stay_status', $casts);
        $this->assertSame(FrontDeskStayStatusEnum::class, $casts['terminal_stay_status']);

        $this->assertArrayHasKey('business_date', $casts);
        $this->assertSame('date', $casts['business_date']);

        $this->assertArrayHasKey('occurred_at', $casts);
        $this->assertSame('datetime', $casts['occurred_at']);

        $this->assertArrayHasKey('created_at', $casts);
        $this->assertSame('datetime', $casts['created_at']);
    }

    public function test_model_relationships_are_structurally_correct(): void
    {
        $e = new FrontDeskCheckoutExecution();

        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->property());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->stay());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->reservation());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->finalReview());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->propertyBusinessDate());
        $this->assertInstanceOf(\Illuminate\Database\Eloquent\Relations\BelongsTo::class, $e->createdBy());
    }

    public function test_structurally_valid_evidence_row_can_be_inserted(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $evidence = $this->createEvidence($stay, $review, $bd, 'idempotent-1');

        $this->assertNotNull($evidence->id);
        $this->assertSame('front_desk_checkout_executions', $evidence->getTable());
        $this->assertDatabaseHas('front_desk_checkout_executions', ['id' => $evidence->id]);
    }

    public function test_terminal_stay_status_is_cast_to_checked_out(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $evidence = $this->createEvidence($stay, $review, $bd, 'idempotent-2');

        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $evidence->terminal_stay_status);
        $this->assertSame('CHECKED_OUT', $evidence->terminal_stay_status->value);

        // Re-fetch
        $fresh = FrontDeskCheckoutExecution::find($evidence->id);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $fresh->terminal_stay_status);
    }

    public function test_same_property_same_idempotency_key_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $this->createEvidence($stay, $review, $bd, 'idempotent-3');

        $this->expectException(QueryException::class);
        $this->createEvidence($stay, $review, $bd, 'idempotent-3', 'different-seed');
    }

    public function test_different_properties_may_use_same_idempotency_key(): void
    {
        $stay1 = $this->makeInHouseStay($this->property);
        $review1 = $this->makeFinalReview($this->property, $stay1);
        $bd1 = $this->makeBusinessDate($this->property);

        $stay2 = $this->makeInHouseStay($this->otherProperty);
        $review2 = $this->makeFinalReview($this->otherProperty, $stay2);
        $bd2 = $this->makeBusinessDate($this->otherProperty);

        // Same idempotency key, different properties
        $e1 = new FrontDeskCheckoutExecution();
        $e1->forceFill([
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay1->id,
            'reservation_id' => $stay1->reservation_id,
            'idempotency_key' => 'cross-prop-key',
            'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
            'front_desk_final_review_id' => $review1->id,
            'property_business_date_id' => $bd1->id,
            'business_date' => $bd1->business_date,
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => $this->sha256('na-cross-1'),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => $this->sha256('pms-cross-1'),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => $this->sha256('gc-cross-1'),
            'source_hash' => $this->sha256('cross-1'),
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'created_at' => now(),
        ])->save();

        $e2 = new FrontDeskCheckoutExecution();
        $e2->forceFill([
            'property_id' => $this->otherProperty->id,
            'front_desk_stay_id' => $stay2->id,
            'reservation_id' => $stay2->reservation_id,
            'idempotency_key' => 'cross-prop-key',
            'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
            'front_desk_final_review_id' => $review2->id,
            'property_business_date_id' => $bd2->id,
            'business_date' => $bd2->business_date,
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => $this->sha256('na-cross-2'),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => $this->sha256('pms-cross-2'),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => $this->sha256('gc-cross-2'),
            'source_hash' => $this->sha256('cross-2'),
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'created_at' => now(),
        ])->save();

        $this->assertNotNull($e1->id);
        $this->assertNotNull($e2->id);
        $this->assertNotSame($e1->id, $e2->id);
    }

    public function test_second_evidence_for_same_stay_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $this->createEvidence($stay, $review, $bd, 'idempotent-4');

        // Different idempotency_key, same stay — should still be rejected
        $this->expectException(QueryException::class);
        $this->createEvidence($stay, $review, $bd, 'idempotent-5');
    }

    public function test_duplicate_property_source_hash_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $sourceHash = $this->sha256('duplicate-hash');
        $naFp = $this->sha256('na-dup');
        $pmsFp = $this->sha256('pms-dup');
        $gcFp = $this->sha256('gc-dup');

        $e1 = new FrontDeskCheckoutExecution();
        $e1->forceFill([
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $stay->reservation_id,
            'idempotency_key' => 'dup-hash-1',
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
            'source_hash' => $sourceHash,
            'occurred_at' => now(),
            'created_by' => $this->actor->id,
            'created_at' => now(),
        ])->save();

        // Different stay but same property + source_hash
        $stay2 = $this->makeInHouseStay($this->property);
        $review2 = $this->makeFinalReview($this->property, $stay2);
        try {
            $e2 = new FrontDeskCheckoutExecution();
            $e2->forceFill([
                'property_id' => $this->property->id,
                'front_desk_stay_id' => $stay2->id,
                'reservation_id' => $stay2->reservation_id,
                'idempotency_key' => 'dup-hash-2',
                'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
                'front_desk_final_review_id' => $review2->id,
                'property_business_date_id' => $bd->id,
                'business_date' => $bd->business_date,
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => $naFp,
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => $pmsFp,
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => $gcFp,
                'source_hash' => $sourceHash,
                'occurred_at' => now(),
                'created_by' => $this->actor->id,
                'created_at' => now(),
            ])->save();
            $this->fail('Duplicate property + source_hash should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fd_ce_source_hash_unique', $e->getMessage());
        }
    }

    public function test_malformed_fingerprint_is_rejected_by_pg(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        try {
            $e = new FrontDeskCheckoutExecution();
            $e->forceFill([
                'property_id' => $this->property->id,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'idempotency_key' => 'bad-fp-1',
                'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
                'front_desk_final_review_id' => $review->id,
                'property_business_date_id' => $bd->id,
                'business_date' => $bd->business_date,
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => 'NOT-A-SHA256-HASH!!!!!',
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => $this->sha256('pms-ok'),
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => $this->sha256('gc-ok'),
                'source_hash' => $this->sha256('source-ok'),
                'occurred_at' => now(),
                'created_by' => $this->actor->id,
                'created_at' => now(),
            ])->save();
            $this->fail('Malformed fingerprint should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fd_ce_na_fingerprint_sha256', $e->getMessage());
        }
    }

    public function test_non_checked_out_terminal_status_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        try {
            $e = new FrontDeskCheckoutExecution();
            $e->forceFill([
                'property_id' => $this->property->id,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'idempotency_key' => 'bad-status-1',
                'terminal_stay_status' => 'IN_HOUSE',
                'front_desk_final_review_id' => $review->id,
                'property_business_date_id' => $bd->id,
                'business_date' => $bd->business_date,
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => $this->sha256('na-bad-status'),
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => $this->sha256('pms-bad-status'),
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => $this->sha256('gc-bad-status'),
                'source_hash' => $this->sha256('bad-status-source'),
                'occurred_at' => now(),
                'created_by' => $this->actor->id,
                'created_at' => now(),
            ])->save();
            $this->fail('Non-CHECKED_OUT terminal status should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fd_ce_terminal_status_check', $e->getMessage());
        }
    }

    public function test_blank_idempotency_key_is_rejected(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        try {
            $e = new FrontDeskCheckoutExecution();
            $e->forceFill([
                'property_id' => $this->property->id,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'idempotency_key' => '',
                'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
                'front_desk_final_review_id' => $review->id,
                'property_business_date_id' => $bd->id,
                'business_date' => $bd->business_date,
                'night_audit_source_status' => 'NA_A2_CLEAR',
                'night_audit_source_fingerprint' => $this->sha256('na-blank'),
                'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
                'pms_financial_attestation_fingerprint' => $this->sha256('pms-blank'),
                'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
                'general_cashier_attestation_fingerprint' => $this->sha256('gc-blank'),
                'source_hash' => $this->sha256('blank-source'),
                'occurred_at' => now(),
                'created_by' => $this->actor->id,
                'created_at' => now(),
            ])->save();
            $this->fail('Blank idempotency key should have been rejected.');
        } catch (QueryException $e) {
            $this->assertStringContainsString('fd_ce_idempotency_not_blank', $e->getMessage());
        }
    }

    public function test_eloquent_update_throws_immutability_exception(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $evidence = $this->createEvidence($stay, $review, $bd, 'idempotent-immut-up');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE');
        $evidence->night_audit_source_status = 'MUTATED';
        $evidence->save();
    }

    public function test_eloquent_delete_throws_immutability_exception(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $evidence = $this->createEvidence($stay, $review, $bd, 'idempotent-immut-del');

        $this->expectException(DomainException::class);
        $this->expectExceptionMessage('FD_C1_CHECKOUT_EXECUTION_EVIDENCE_IMMUTABLE');
        $evidence->delete();
    }

    public function test_persisted_row_remains_unchanged_after_rejected_mutations(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $evidence = $this->createEvidence($stay, $review, $bd, 'idempotent-unchanged');

        $originalStatus = $evidence->night_audit_source_status;
        $originalFp = $evidence->night_audit_source_fingerprint;

        try {
            $evidence->night_audit_source_status = 'MUTATED';
            $evidence->save();
        } catch (DomainException) {
            // Expected
        }

        $fresh = FrontDeskCheckoutExecution::find($evidence->id);
        $this->assertNotNull($fresh);
        $this->assertSame($originalStatus, $fresh->night_audit_source_status);
        $this->assertSame($originalFp, $fresh->night_audit_source_fingerprint);
        $this->assertSame(FrontDeskStayStatusEnum::CheckedOut, $fresh->terminal_stay_status);
        $this->assertSame($stay->id, $fresh->front_desk_stay_id);
    }

    public function test_no_front_desk_stay_transition_occurs(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        $this->createEvidence($stay, $review, $bd, 'idempotent-no-trans');

        $freshStay = FrontDeskStay::find($stay->id);
        $this->assertSame(FrontDeskStayStatusEnum::InHouse->value, $freshStay->status->value);
        $this->assertSame(FrontDeskStayStatusEnum::InHouse, $freshStay->status);
    }

    public function test_source_stay_remains_in_house(): void
    {
        $stay = $this->makeInHouseStay($this->property);

        $this->assertSame('IN_HOUSE', $stay->status->value);

        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);
        $this->createEvidence($stay, $review, $bd, 'idempotent-stay-ih');

        $fresh = FrontDeskStay::find($stay->id);
        $this->assertSame('IN_HOUSE', $fresh->status->value);
    }

    public function test_no_foreign_domain_lifecycle_mutation_occurs(): void
    {
        $stay = $this->makeInHouseStay($this->property);
        $review = $this->makeFinalReview($this->property, $stay);
        $bd = $this->makeBusinessDate($this->property);

        // Record pre-state
        $preBdStatus = $bd->status;
        $preReviewStatus = $review->final_review_status;

        $this->createEvidence($stay, $review, $bd, 'idempotent-no-fdm');

        // Foreign domain state unchanged
        $freshBd = PropertyBusinessDate::find($bd->id);
        $this->assertSame($preBdStatus, $freshBd->status);
        $this->assertTrue($freshBd->is_open);

        $freshReview = FrontDeskDepartureCheckoutFinalReview::find($review->id);
        $this->assertSame($preReviewStatus, $freshReview->final_review_status);
    }
}
