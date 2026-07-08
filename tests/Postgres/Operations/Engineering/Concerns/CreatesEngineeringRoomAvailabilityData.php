<?php

namespace Tests\Postgres\Operations\Engineering\Concerns;

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
use Shared\Services\CurrentPropertyService;

trait CreatesEngineeringRoomAvailabilityData
{
    protected Company $company;
    protected Company $otherCompany;
    protected Property $property;
    protected Property $otherProperty;
    protected Property $otherTenantProperty;
    protected User $engineeringActor;
    protected User $frontDeskActor;
    protected User $financeActor;

    protected function setUpEngineeringRoomAvailabilityFixture(): void
    {
        $this->company = $this->company('ENG RA Company', 'eng-ra-company');
        $this->otherCompany = $this->company('ENG RA Other Company', 'eng-ra-other-company');
        $this->property = $this->property($this->company, 'ENG RA Property', 'eng-ra-property', 'ERAP');
        $this->otherProperty = $this->property($this->company, 'ENG RA Other Property', 'eng-ra-other-property', 'ERAO');
        $this->otherTenantProperty = $this->property($this->otherCompany, 'ENG RA Cross Tenant', 'eng-ra-cross-tenant', 'ERAX');

        $this->engineeringActor = $this->user('Engineering Actor', 'eng-ra-actor@example.test');
        $this->frontDeskActor = $this->user('Front Desk Actor', 'frontdesk-ra-actor@example.test');
        $this->financeActor = $this->user('Finance Actor', 'finance-ra-actor@example.test');

        $this->attachProperty($this->engineeringActor, $this->property);
        $this->attachProperty($this->frontDeskActor, $this->property);
        $this->attachProperty($this->financeActor, $this->property);

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        session($this->propertySession($this->property));

        foreach ($this->roomAvailabilityPermissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->engineeringActor->givePermissionTo([
            EngineeringRoomAvailabilityProjectionService::ENGINEERING_VIEW_PERMISSION,
            EngineeringRoomAvailabilityBlockService::BLOCK_PERMISSION,
            EngineeringRoomAvailabilityBlockService::RELEASE_PERMISSION,
        ]);
        $this->frontDeskActor->givePermissionTo(EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION);
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

    protected function room(Property $property, string $number): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert([
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
        ]);

        return $id;
    }

    protected function reservation(Property $property, string $guestId, string $roomId): string
    {
        $id = (string) Str::ulid();
        DB::table('reservations')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'reservation_number' => 'RES-ERA-' . strtoupper(Str::random(6)),
            'primary_guest_id' => $guestId,
            'adults' => 1,
            'children' => 0,
            'arrival_date' => '2026-07-08',
            'departure_date' => '2026-07-09',
            'nights' => 1,
            'reservation_source' => 'direct',
            'status' => 'confirmed',
            'reserved_room_type' => 'deluxe',
            'assigned_room_id' => $roomId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    protected function guest(Property $property): string
    {
        $id = (string) Str::ulid();
        DB::table('guests')->insert([
            'id' => $id,
            'property_id' => $property->id,
            'guest_code' => 'GST-' . strtoupper(Str::random(6)),
            'full_name' => 'Engineering Availability Guest',
            'guest_type' => 'individual',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $id;
    }

    /**
     * @return array<string, int>
     */
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

    /**
     * @return array<string, string>
     */
    protected function propertySession(Property $property): array
    {
        return [
            'active_property_id' => $property->id,
            'active_company_id' => $property->company_id,
            'current_property_id' => $property->id,
        ];
    }

    /**
     * @return string[]
     */
    protected function roomAvailabilityPermissions(): array
    {
        return [
            EngineeringRoomAvailabilityProjectionService::ENGINEERING_VIEW_PERMISSION,
            EngineeringRoomAvailabilityProjectionService::FRONT_DESK_VIEW_PERMISSION,
            EngineeringRoomAvailabilityBlockService::BLOCK_PERMISSION,
            EngineeringRoomAvailabilityBlockService::RELEASE_PERMISSION,
        ];
    }
}
