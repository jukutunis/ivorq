<?php

namespace Tests\Postgres\Operations\Housekeeping\Concerns;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutExecution;
use Modules\Operations\FrontDesk\Models\FrontDeskCheckoutHousekeepingHandoff;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutFinalReview;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Models\Reservation;
use Shared\Services\CurrentPropertyService;

trait CreatesHousekeepingCheckoutTurnoverIntakeData
{
    protected Company $company;
    protected Property $property;
    protected Property $otherProperty;
    protected User $actor;

    protected function setUpCheckoutTurnoverFixture(): void
    {
        $this->company = Company::create([
            'name' => 'P11 Company',
            'slug' => 'p11-company-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);

        $this->property = $this->p11Property('P11 Property', 'P11P');
        $this->otherProperty = $this->p11Property('P11 Other Property', 'P11O');
        $this->actor = User::create([
            'name' => 'P11 Actor',
            'email' => 'p11-actor-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $this->property->id,
            'current_property_id' => $this->property->id,
            'active_company_id' => $this->company->id,
        ]);
    }

    protected function p11Property(string $name, string $code): Property
    {
        return Property::create([
            'company_id' => $this->company->id,
            'name' => $name,
            'slug' => Str::slug($name) . '-' . Str::lower(Str::random(6)),
            'code' => $code . Str::upper(Str::random(3)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    protected function p11Room(Property $property, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert(array_merge([
            'id' => $id,
            'property_id' => $property->id,
            'room_number' => 'P11-' . Str::upper(Str::random(5)),
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'is_vip' => false,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    protected function p11CheckoutSource(Property $property, string $roomId, array $overrides = []): array
    {
        $guest = Guest::create([
            'property_id' => $property->id,
            'guest_code' => 'P11G-' . Str::upper(Str::random(5)),
            'full_name' => 'P11 Guest',
            'guest_type' => 'individual',
        ]);
        $reservation = Reservation::create([
            'property_id' => $property->id,
            'primary_guest_id' => $guest->id,
            'reservation_number' => 'P11R-' . Str::upper(Str::random(6)),
            'arrival_date' => today(),
            'departure_date' => today()->addDay(),
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'checked_in',
            'reserved_room_type' => 'deluxe',
        ]);
        $businessDate = PropertyBusinessDate::where('property_id', $property->id)
            ->where('is_open', true)
            ->first();
        if (! $businessDate) {
            $businessDate = PropertyBusinessDate::create([
                'property_id' => $property->id,
                'business_date' => today(),
                'status' => PropertyBusinessDateStatusEnum::Open,
                'is_open' => true,
                'timezone_snapshot' => 'UTC',
                'opened_by' => $this->actor->id,
                'opened_at' => now(),
            ]);
        }
        $stay = FrontDeskStay::create([
            'property_id' => $property->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'status' => $overrides['stay_status'] ?? FrontDeskStayStatusEnum::CheckedOut->value,
            'current_room_id' => $roomId,
            'created_by' => $this->actor->id,
            'updated_by' => $this->actor->id,
        ]);
        $occurredAt = Carbon::parse($overrides['occurred_at'] ?? now());
        $finalReview = FrontDeskDepartureCheckoutFinalReview::create([
            'property_id' => $property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $reservation->id,
            'guest_id' => $guest->id,
            'final_review_status' => 'CHECKOUT_FINAL_REVIEW_READY',
            'occurred_at' => $occurredAt,
            'created_by' => $this->actor->id,
            'idempotency_key' => 'p11-review-' . Str::ulid(),
            'source_hash' => hash('sha256', 'review-' . $stay->id),
        ]);

        $execution = new FrontDeskCheckoutExecution();
        $execution->forceFill([
            'property_id' => $property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $reservation->id,
            'idempotency_key' => 'p11-exec-' . Str::ulid(),
            'terminal_stay_status' => FrontDeskStayStatusEnum::CheckedOut,
            'front_desk_final_review_id' => $finalReview->id,
            'property_business_date_id' => $businessDate->id,
            'business_date' => $businessDate->business_date,
            'night_audit_source_status' => 'NA_A2_CLEAR',
            'night_audit_source_fingerprint' => hash('sha256', 'na-' . $stay->id),
            'pms_financial_attestation_status' => 'GLF_E_ATTESTED',
            'pms_financial_attestation_fingerprint' => hash('sha256', 'pms-' . $stay->id),
            'general_cashier_attestation_status' => 'GC_A2_ATTESTED',
            'general_cashier_attestation_fingerprint' => hash('sha256', 'gc-' . $stay->id),
            'source_hash' => hash('sha256', 'exec-' . $stay->id),
            'occurred_at' => $occurredAt,
            'created_by' => $this->actor->id,
            'created_at' => $occurredAt,
        ])->save();

        $handoffKey = 'p9-hk-handoff|' . $property->id . '|' . $execution->id;
        $handoffHash = hash('sha256', json_encode([
            'property_id' => $execution->property_id,
            'front_desk_stay_id' => $execution->front_desk_stay_id,
            'reservation_id' => $execution->reservation_id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $execution->property_business_date_id,
            'business_date' => $execution->business_date?->format('Y-m-d'),
            'terminal_stay_status' => $execution->terminal_stay_status?->value,
            'execution_source_hash' => $execution->source_hash,
            'occurred_at' => $occurredAt->toDateTimeString(),
        ], JSON_UNESCAPED_SLASHES));

        $handoff = new FrontDeskCheckoutHousekeepingHandoff();
        $handoff->forceFill([
            'property_id' => $property->id,
            'front_desk_stay_id' => $stay->id,
            'reservation_id' => $reservation->id,
            'checkout_execution_id' => $execution->id,
            'property_business_date_id' => $businessDate->id,
            'business_date' => $businessDate->business_date,
            'idempotency_key' => $handoffKey,
            'correlation_key' => 'p9-checkout|' . $property->id . '|' . $stay->id . '|' . $execution->id,
            'source_hash' => $handoffHash,
            'available_at' => now()->subDay(),
            'occurred_at' => $occurredAt,
            'created_at' => $occurredAt,
            'updated_at' => $occurredAt,
        ])->save();

        return compact('guest', 'reservation', 'businessDate', 'stay', 'finalReview', 'execution', 'handoff');
    }
}
