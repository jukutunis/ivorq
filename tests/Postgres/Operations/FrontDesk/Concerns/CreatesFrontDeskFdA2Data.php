<?php

namespace Tests\Postgres\Operations\FrontDesk\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutAuthorizationService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutFinalReviewService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Shared\Services\CurrentPropertyService;

trait CreatesFrontDeskFdA2Data
{
    protected Company $company;
    protected Company $otherCompany;
    protected Property $property;
    protected Property $otherProperty;
    protected Property $otherTenantProperty;
    protected User $frontDeskActor;
    protected User $frontDeskViewOnlyActor;
    protected User $engineeringActor;
    protected User $financeActor;

    protected function setUpFrontDeskFdA2Fixture(): void
    {
        $this->company = $this->company('FD A2 Company', 'fd-a2-company');
        $this->otherCompany = $this->company('FD A2 Other Company', 'fd-a2-other-company');
        $this->property = $this->property($this->company, 'FD A2 Property', 'fd-a2-property', 'FD2P');
        $this->otherProperty = $this->property($this->company, 'FD A2 Other Property', 'fd-a2-other-property', 'FD2O');
        $this->otherTenantProperty = $this->property($this->otherCompany, 'FD A2 Cross Tenant', 'fd-a2-cross-tenant', 'FD2X');

        $this->frontDeskActor = $this->user('FD A2 Actor', 'fd-a2-actor@example.test');
        $this->frontDeskViewOnlyActor = $this->user('FD A2 View Only', 'fd-a2-view@example.test');
        $this->engineeringActor = $this->user('FD A2 Engineering', 'fd-a2-eng@example.test');
        $this->financeActor = $this->user('FD A2 Finance', 'fd-a2-finance@example.test');

        foreach ([$this->frontDeskActor, $this->frontDeskViewOnlyActor, $this->engineeringActor, $this->financeActor] as $user) {
            $this->attachProperty($user, $this->property);
        }

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        session($this->propertySession($this->property));

        foreach ($this->fdA2Permissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->frontDeskActor->givePermissionTo([
            ArrivalEligibilityProjectionService::VIEW_PERMISSION,
            EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION,
            FrontDeskRoomAssignmentService::CREATE_PERMISSION,
            FrontDeskCheckInService::EXECUTE_PERMISSION,
            FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION,
            FrontDeskRoomMoveService::EXECUTE_PERMISSION,
            FrontDeskCheckoutReadinessProjectionService::VIEW_PERMISSION,
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION,
            FrontDeskDeparturePreparationEventService::CREATE_PERMISSION,
            FrontDeskDepartureOperationalHandoverService::CREATE_PERMISSION,
            FrontDeskDepartureClosureReadinessService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutEligibilityService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutAuthorizationService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutFinalReviewService::CREATE_PERMISSION,
        ]);

        $this->frontDeskViewOnlyActor->givePermissionTo([
            ArrivalEligibilityProjectionService::VIEW_PERMISSION,
            EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION,
            FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION,
        ]);

        $this->engineeringActor->givePermissionTo([
            EngineeringRoomAvailabilityProjectionService::ENGINEERING_VIEW_PERMISSION,
            EngineeringRoomAvailabilityBlockService::BLOCK_PERMISSION,
            EngineeringRoomAvailabilityBlockService::RELEASE_PERMISSION,
        ]);

        Permission::firstOrCreate(['name' => 'finance.journal-entry.post', 'guard_name' => 'web']);
        $this->financeActor->givePermissionTo('finance.journal-entry.post');
    }

    protected function company(string $name, string $slug): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
    }

    protected function property(Company $company, string $name, string $slug, string $code): Property
    {
        return Property::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'code' => $code . Str::upper(Str::random(2)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    protected function user(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::before($email, '@') . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    protected function attachProperty(User $user, Property $property): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    protected function room(Property $property, string $number, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert(array_merge([
            'id' => $id,
            'property_id' => $property->id,
            'room_number' => $number,
            'room_type' => 'deluxe',
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_arrival',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    protected function guest(Property $property, string $name = 'FD A2 Guest'): string
    {
        $id = (string) Str::ulid();
        DB::table('guests')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'guest_code' => 'GST-' . strtoupper(Str::random(6)),
            'full_name' => $name,
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function reservation(Property $property, string $guestId, string $number, string $status = 'confirmed', ?string $roomId = null, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('reservations')->insert(array_merge([
            'id' => $id,
            'property_id' => $property->id,
            'reservation_number' => $number,
            'primary_guest_id' => $guestId,
            'adults' => 1,
            'children' => 0,
            'arrival_date' => '2026-07-08',
            'departure_date' => '2026-07-09',
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => $status,
            'reserved_room_type' => 'deluxe',
            'assigned_room_id' => $roomId,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    protected function activeEngineeringBlock(string $roomId, string $reason = 'Engineering block'): string
    {
        $id = (string) Str::ulid();
        DB::table('engineering_room_availability_blocks')->insert([
            'id' => $id,
            'property_id' => $this->property->id,
            'room_id' => $roomId,
            'block_status' => 'ACTIVE',
            'block_reason' => $reason,
            'started_at' => now(),
            'started_by' => $this->engineeringActor->id,
            'idempotency_key' => 'eng-block-' . Str::ulid(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function assignReadyReservation(?string $roomNumber = '901'): array
    {
        $room = $roomNumber === null ? null : $this->room($this->property, $roomNumber);
        $guest = $this->guest($this->property);
        $reservation = $this->reservation($this->property, $guest, 'RES-FD2-' . strtoupper(Str::random(5)), 'confirmed', $room);

        return [$reservation, $guest, $room];
    }

    protected function propertySession(Property $property): array
    {
        return [
            'active_property_id' => $property->id,
            'active_company_id' => $property->company_id,
            'current_property_id' => $property->id,
        ];
    }

    protected function domainTableCounts(): array
    {
        $tables = [
            'reservations',
            'guests',
            'rooms',
            'room_blocks',
            'work_orders',
            'engineering_room_availability_blocks',
            'stays',
            'folios',
            'folio_items',
            'journal_candidates',
            'journal_candidate_lines',
            'gl_journal_entries',
            'gl_journal_entry_lines',
            'gl_ledger_balances',
            'payment_proposals',
            'payment_proposal_items',
            'payment_executions',
            'cashbook_transactions',
            'controlled_bank_statement_lines',
            'gl_financial_periods',
            'property_business_dates',
        ];

        return collect($tables)
            ->filter(fn (string $table) => Schema::hasTable($table))
            ->mapWithKeys(fn (string $table) => [$table => DB::table($table)->count()])
            ->all();
    }

    protected function fdA2Permissions(): array
    {
        return [
            ArrivalEligibilityProjectionService::VIEW_PERMISSION,
            EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
            EngineeringRoomAvailabilityProjectionService::ENGINEERING_VIEW_PERMISSION,
            EngineeringRoomAvailabilityBlockService::BLOCK_PERMISSION,
            EngineeringRoomAvailabilityBlockService::RELEASE_PERMISSION,
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION,
            FrontDeskRoomAssignmentService::CREATE_PERMISSION,
            FrontDeskCheckInService::EXECUTE_PERMISSION,
            FrontDeskCheckInService::IN_HOUSE_VIEW_PERMISSION,
            FrontDeskRoomMoveService::EXECUTE_PERMISSION,
            FrontDeskCheckoutReadinessProjectionService::VIEW_PERMISSION,
            FrontDeskDepartureQueueProjectionService::VIEW_PERMISSION,
            FrontDeskDeparturePreparationEventService::CREATE_PERMISSION,
            FrontDeskDepartureOperationalHandoverService::CREATE_PERMISSION,
            FrontDeskDepartureClosureReadinessService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutEligibilityService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutAuthorizationService::CREATE_PERMISSION,
            FrontDeskDepartureCheckoutFinalReviewService::CREATE_PERMISSION,
        ];
    }

    protected function checkedInStay(string $roomNumber): array
    {
        [$reservation, , $room] = $this->assignReadyReservation($roomNumber);
        $assigned = app(FrontDeskRoomAssignmentService::class)->assign(
            $this->frontDeskActor, $reservation, $room, null, 'assign-' . Str::ulid()
        );
        $context = 'check-in-' . Str::ulid();
        $hash = app(FrontDeskCheckInService::class)->prepareConfirmation(
            $this->frontDeskActor, $assigned['stay']->id, $context
        );
        app(SensitiveActionConfirmationService::class)->confirm(
            $this->frontDeskActor, FrontDeskCheckInService::INTENT,
            'password', $this->property->company_id, $this->property->id, $hash
        );
        $stay = app(FrontDeskCheckInService::class)->checkIn(
            $this->frontDeskActor, $assigned['stay']->id, $context
        );

        return [$stay->fresh(), $room, $reservation];
    }
}
