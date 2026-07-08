<?php

namespace Tests\Postgres\Operations\Housekeeping\Concerns;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Shared\Services\CurrentPropertyService;

trait CreatesHousekeepingRoomReadinessData
{
    protected Company $company;
    protected Company $otherCompany;
    protected Property $property;
    protected Property $otherProperty;
    protected Property $otherTenantProperty;
    protected User $housekeepingActor;
    protected User $housekeepingInspector;
    protected User $frontDeskActor;
    protected User $engineeringActor;
    protected User $financeActor;

    protected function setUpHousekeepingRoomReadinessFixture(): void
    {
        $this->company = $this->hkCompany('HK Readiness Company', 'hk-readiness-company');
        $this->otherCompany = $this->hkCompany('HK Readiness Other Company', 'hk-readiness-other-company');
        $this->property = $this->hkProperty($this->company, 'HK Readiness Property', 'hk-readiness-property', 'HKRP');
        $this->otherProperty = $this->hkProperty($this->company, 'HK Readiness Other Property', 'hk-readiness-other-property', 'HKRO');
        $this->otherTenantProperty = $this->hkProperty($this->otherCompany, 'HK Readiness Cross Tenant', 'hk-readiness-cross-tenant', 'HKRX');

        $this->housekeepingActor = $this->hkUser('Housekeeping Actor', 'hk-ra-readiness@example.test');
        $this->housekeepingInspector = $this->hkUser('Housekeeping Inspector', 'hk-ra-inspector@example.test');
        $this->frontDeskActor = $this->hkUser('Front Desk Actor', 'frontdesk-hk-readiness@example.test');
        $this->engineeringActor = $this->hkUser('Engineering Actor', 'engineering-hk-readiness@example.test');
        $this->financeActor = $this->hkUser('Finance Actor', 'finance-hk-readiness@example.test');

        foreach ([$this->housekeepingActor, $this->housekeepingInspector, $this->frontDeskActor, $this->engineeringActor, $this->financeActor] as $user) {
            $this->hkAttachProperty($user, $this->property);
        }

        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        setPermissionsTeamId($this->property->id);
        session($this->hkPropertySession($this->property));

        foreach ($this->hkReadinessPermissions() as $permission) {
            Permission::firstOrCreate(['name' => $permission, 'guard_name' => 'web']);
        }

        $this->housekeepingActor->givePermissionTo([
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
        ]);

        $this->housekeepingInspector->givePermissionTo([
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ]);

        $this->frontDeskActor->givePermissionTo(
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION
        );

        Permission::firstOrCreate(['name' => 'engineering.room-availability.view', 'guard_name' => 'web']);
        $this->engineeringActor->givePermissionTo('engineering.room-availability.view');

        Permission::firstOrCreate(['name' => 'finance.journal-entry.post', 'guard_name' => 'web']);
        $this->financeActor->givePermissionTo('finance.journal-entry.post');
    }

    protected function hkCompany(string $name, string $slug): Company
    {
        return Company::create([
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'is_active' => true,
        ]);
    }

    protected function hkProperty(Company $company, string $name, string $slug, string $code): Property
    {
        return Property::create([
            'company_id' => $company->id,
            'name' => $name,
            'slug' => $slug . '-' . Str::lower(Str::random(6)),
            'code' => $code . Str::upper(Str::random(6)),
            'timezone' => 'UTC',
            'currency' => 'USD',
            'is_active' => true,
        ]);
    }

    protected function hkUser(string $name, string $email): User
    {
        return User::create([
            'name' => $name,
            'email' => Str::before($email, '@') . '-' . Str::lower(Str::random(6)) . '@example.test',
            'password' => Hash::make('password'),
            'is_active' => true,
        ]);
    }

    protected function hkAttachProperty(User $user, Property $property): void
    {
        $user->properties()->attach($property->id, [
            'is_default' => true,
            'status' => 'active',
            'joined_at' => now(),
        ]);
    }

    protected function hkRoom(Property $property, string $number, array $overrides = []): string
    {
        $id = (string) Str::ulid();
        DB::table('rooms')->insert(array_merge([
            'id' => $id,
            'property_id' => $property->id,
            'room_number' => $number,
            'room_type' => 'deluxe',
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
            'occupancy_status' => 'vacant',
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ], $overrides));

        return $id;
    }

    protected function hkDirtyRoom(Property $property, string $number): string
    {
        return $this->hkRoom($property, $number, [
            'cleanliness_status' => 'dirty',
            'readiness_state' => 'waiting_cleaning',
        ]);
    }

    protected function hkCleanRoom(Property $property, string $number): string
    {
        return $this->hkRoom($property, $number, [
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
        ]);
    }

    protected function hkInspectedRoom(Property $property, string $number): string
    {
        return $this->hkRoom($property, $number, [
            'cleanliness_status' => 'inspected',
            'readiness_state' => 'ready_for_sale',
        ]);
    }

    protected function hkVipRoom(Property $property, string $number): string
    {
        return $this->hkRoom($property, $number, [
            'cleanliness_status' => 'clean',
            'readiness_state' => 'waiting_inspection',
            'is_vip' => true,
        ]);
    }

    /**
     * @return array<string, int>
     */
    protected function hkDomainTableCounts(): array
    {
        $tables = [
            'reservations',
            'guests',
            'rooms',
            'room_blocks',
            'work_orders',
            'engineering_room_availability_blocks',
            'housekeeping_room_readiness_transitions',
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
    protected function hkPropertySession(Property $property): array
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
    protected function hkReadinessPermissions(): array
    {
        return [
            HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION,
            HousekeepingRoomReadinessProjectionService::FRONT_DESK_VIEW_PERMISSION,
            HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION,
            HousekeepingRoomReadinessTransitionService::SUBMIT_INSPECTION_PERMISSION,
            HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION,
        ];
    }
}
