<?php

namespace Tests\Postgres\Operations\FrontDesk;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Tests\PostgresTestCase;

class FrontDeskCheckoutConfirmationMigrationProofTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;
    private Property $property;
    private User $actor;
    private FrontDeskStay $stay;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'P8 Migration Co',
            'slug' => 'p8-migration-co-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'P8 Migration Property',
            'slug' => 'p8-migration-property-' . Str::lower(Str::random(6)),
            'code' => Str::upper(Str::random(4)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor = User::create([
            'name' => 'P8 Migration Actor',
            'email' => 'p8-migration-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => bcrypt('password'),
            'is_active' => true,
        ]);
        $this->stay = $this->stay();
    }

    public function test_schema_contains_exact_package8_tables_columns_constraints_and_triggers(): void
    {
        $this->assertTrue(Schema::hasTable('checkout_sensitive_confirmation_issuances'));
        $this->assertTrue(Schema::hasTable('checkout_sensitive_confirmation_consumptions'));

        foreach ([
            'id', 'confirmation_identity', 'intent', 'actor_id', 'company_id', 'property_id',
            'front_desk_stay_id', 'checkout_idempotency_key', 'session_fingerprint',
            'confirmation_fingerprint', 'confirmed_at', 'expires_at', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('checkout_sensitive_confirmation_issuances', $column));
        }

        foreach ([
            'id', 'issuance_id', 'confirmation_identity', 'confirmation_fingerprint',
            'actor_id', 'company_id', 'property_id', 'front_desk_stay_id',
            'checkout_idempotency_key', 'consumed_at', 'created_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('checkout_sensitive_confirmation_consumptions', $column));
        }

        foreach ([
            'p8_csc_issue_identity_unique', 'p8_csc_issue_fingerprint_unique',
            'p8_csc_issue_context_unique', 'p8_csc_issue_intent_check',
            'p8_csc_issue_idem_check', 'p8_csc_issue_session_sha',
            'p8_csc_issue_confirm_sha', 'p8_csc_issue_time_check',
            'p8_csc_consume_issuance_unique', 'p8_csc_consume_checkout_unique',
            'p8_csc_consume_issue_context_fk', 'p8_csc_consume_idem_check',
            'p8_csc_consume_confirm_sha',
            'fd_ce_p8_confirmation_consumption_unique',
            'fd_ce_p8_confirmation_consumption_fk',
            'fd_ce_p8_confirmation_all_or_none',
            'fd_ce_p8_confirmation_fingerprint_sha',
            'fd_ce_p8_confirmation_time_order',
        ] as $constraint) {
            $this->assertTrue($this->constraintExists($constraint), "Missing constraint {$constraint}");
        }

        foreach ([
            'p8_csc_issue_no_update', 'p8_csc_issue_no_delete',
            'p8_csc_consume_insert_guard', 'p8_csc_consume_no_update', 'p8_csc_consume_no_delete',
        ] as $trigger) {
            $this->assertTrue($this->triggerExists($trigger), "Missing trigger {$trigger}");
        }

        foreach ([
            'checkout_confirmation_consumption_id',
            'checkout_confirmation_fingerprint',
            'checkout_confirmed_at',
            'checkout_confirmation_expires_at',
            'checkout_confirmation_consumed_at',
        ] as $column) {
            $this->assertTrue(Schema::hasColumn('front_desk_checkout_executions', $column));
        }
    }

    public function test_issuance_and_consumption_are_update_delete_immutable(): void
    {
        $issuanceId = $this->insertIssuance('immutability-key');

        $this->assertQueryFails(
            fn () => DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->update(['intent' => 'mutated']),
            'P8_CHECKOUT_CONFIRMATION_ISSUANCE_IMMUTABLE'
        );
        $this->assertQueryFails(
            fn () => DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->delete(),
            'P8_CHECKOUT_CONFIRMATION_ISSUANCE_IMMUTABLE'
        );

        $consumptionId = $this->insertConsumption($issuanceId);

        $this->assertQueryFails(
            fn () => DB::table('checkout_sensitive_confirmation_consumptions')->where('id', $consumptionId)->update(['checkout_idempotency_key' => 'mutated']),
            'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'
        );
        $this->assertQueryFails(
            fn () => DB::table('checkout_sensitive_confirmation_consumptions')->where('id', $consumptionId)->delete(),
            'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_IMMUTABLE'
        );
    }

    public function test_raw_mismatched_expired_and_duplicate_consumption_are_rejected(): void
    {
        $issuanceId = $this->insertIssuance('raw-key-1');

        $this->assertQueryFails(
            fn () => $this->insertConsumption($issuanceId, ['property_id' => (string) Str::ulid()]),
            'foreign key constraint'
        );

        $expiredIssuance = $this->insertIssuance('raw-key-expired', now()->subHours(2), now()->subHour());
        $this->assertQueryFails(
            fn () => $this->insertConsumption($expiredIssuance),
            'P8_CHECKOUT_CONFIRMATION_CONSUMPTION_EXPIRED'
        );

        $this->insertConsumption($issuanceId);
        $this->assertQueryFails(
            fn () => $this->insertConsumption($issuanceId),
            'p8_csc_consume_issuance_unique'
        );

        $secondIssuance = $this->insertIssuance('raw-key-1');
        $this->assertQueryFails(
            fn () => $this->insertConsumption($secondIssuance),
            'p8_csc_consume_checkout_unique'
        );
    }

    private function stay(): FrontDeskStay
    {
        $guest = Guest::create([
            'property_id' => $this->property->id,
            'guest_code' => 'P8M-' . Str::upper(Str::random(5)),
            'full_name' => 'P8 Migration Guest',
            'guest_type' => 'individual',
        ]);
        $reservation = Reservation::create([
            'property_id' => $this->property->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'P8M-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'standard',
        ]);

        return FrontDeskStay::create([
            'property_id' => $this->property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => FrontDeskStayStatusEnum::InHouse,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
    }

    private function insertIssuance(string $idempotencyKey, $confirmedAt = null, $expiresAt = null): string
    {
        $id = (string) Str::ulid();
        $confirmedAt ??= now();
        $expiresAt ??= now()->addMinutes(15);
        $identity = (string) Str::ulid();

        DB::table('checkout_sensitive_confirmation_issuances')->insert([
            'id' => $id,
            'confirmation_identity' => $identity,
            'intent' => 'frontdesk-checkout-execution',
            'actor_id' => $this->actor->id,
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'front_desk_stay_id' => $this->stay->id,
            'checkout_idempotency_key' => $idempotencyKey,
            'session_fingerprint' => hash('sha256', 'session-' . $id),
            'confirmation_fingerprint' => hash('sha256', 'confirmation-' . $id),
            'confirmed_at' => $confirmedAt,
            'expires_at' => $expiresAt,
            'created_at' => $confirmedAt,
        ]);

        return $id;
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function insertConsumption(string $issuanceId, array $overrides = []): string
    {
        $issuance = DB::table('checkout_sensitive_confirmation_issuances')->where('id', $issuanceId)->first();
        $id = (string) Str::ulid();

        DB::table('checkout_sensitive_confirmation_consumptions')->insert(array_merge([
            'id' => $id,
            'issuance_id' => $issuance->id,
            'confirmation_identity' => $issuance->confirmation_identity,
            'confirmation_fingerprint' => $issuance->confirmation_fingerprint,
            'actor_id' => $issuance->actor_id,
            'company_id' => $issuance->company_id,
            'property_id' => $issuance->property_id,
            'front_desk_stay_id' => $issuance->front_desk_stay_id,
            'checkout_idempotency_key' => $issuance->checkout_idempotency_key,
            'consumed_at' => now(),
            'created_at' => now(),
        ], $overrides));

        return $id;
    }

    private function constraintExists(string $name): bool
    {
        return DB::table('pg_constraint')->where('conname', $name)->exists();
    }

    private function triggerExists(string $name): bool
    {
        return DB::table('pg_trigger')->where('tgname', $name)->exists();
    }

    private function assertQueryFails(callable $callback, string $needle): void
    {
        try {
            $callback();
            $this->fail("Expected query failure containing {$needle}.");
        } catch (QueryException $exception) {
            $this->assertStringContainsString($needle, $exception->getMessage());
        }
    }
}
