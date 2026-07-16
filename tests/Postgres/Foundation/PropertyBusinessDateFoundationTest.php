<?php

namespace Tests\Postgres\Foundation;

use Carbon\Carbon;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Modules\Foundation\Authorization\Models\Permission;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Modules\Foundation\Property\Services\PropertyBusinessDateAuthorizationService;
use Modules\Foundation\Property\Services\PropertyBusinessDateInitializationService;
use Modules\Foundation\Property\Services\PropertyBusinessDateProjectionService;
use Modules\Foundation\User\Models\User;
use RuntimeException;
use Shared\Services\CurrentPropertyService;
use Spatie\Permission\PermissionRegistrar;
use Tests\PostgresTestCase;

class PropertyBusinessDateFoundationTest extends PostgresTestCase
{
    use DatabaseMigrations;

    private Company $company;
    private Property $property;
    private User $actor;

    protected function setUp(): void
    {
        parent::setUp();

        Permission::firstOrCreate(['name' => PropertyBusinessDateAuthorizationService::VIEW_PERMISSION, 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION, 'guard_name' => 'web']);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->createAuthorizedContext();
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        app(CurrentPropertyService::class)->clear();
        parent::tearDown();
    }

    public function test_first_initialization_is_server_derived_idempotent_and_projectable(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-16 23:30:00', 'UTC'));

        $service = app(PropertyBusinessDateInitializationService::class);
        $first = $service->initialize($this->actor);
        $second = $service->initialize($this->actor);

        $this->assertSame($first->id, $second->id);
        $this->assertSame('2026-07-17', $first->business_date->format('Y-m-d'));
        $this->assertSame('Asia/Makassar', $first->timezone_snapshot);
        $this->assertSame(PropertyBusinessDateStatusEnum::Open, $first->status);
        $this->assertTrue($first->is_open);
        $this->assertSame($this->actor->id, $first->opened_by);
        $this->assertSame('2026-07-16T23:30:00.000000Z', $first->opened_at->toJSON());
        $this->assertNull($first->closed_by);
        $this->assertNull($first->closed_at);
        $this->assertSame(1, PropertyBusinessDate::withoutGlobalScopes()->where('property_id', $this->property->id)->count());

        $projection = app(PropertyBusinessDateProjectionService::class)->project($this->actor);
        $again = app(PropertyBusinessDateProjectionService::class)->project($this->actor);

        $this->assertSame('BUSINESS_DATE_OPEN', $projection['status']);
        $this->assertSame('PROPERTY_BUSINESS_DATE_SOURCE_PROVEN', $projection['source_classification']);
        $this->assertSame('Business Date / Night Audit', $projection['owner']);
        $this->assertTrue($projection['read_only']);
        $this->assertSame($first->id, $projection['property_business_date_id']);
        $this->assertSame($this->property->id, $projection['property_id']);
        $this->assertSame('2026-07-17', $projection['business_date']);
        $this->assertSame('Open', $projection['lifecycle_status']);
        $this->assertSame('Asia/Makassar', $projection['property_timezone']);
        $this->assertSame($first->opened_at->copy()->utc()->toISOString(), $projection['opened_at']);
        $this->assertSame($this->actor->id, $projection['opened_by']);
        $this->assertSame($projection['source_fingerprint'], $again['source_fingerprint']);
        $this->assertNotSame($projection['evaluated_at'], '');
    }

    public function test_history_without_open_invalid_timezone_legacy_evidence_and_timezone_mismatch_fail_closed(): void
    {
        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-15',
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by' => $this->actor->id,
        ]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(PropertyBusinessDateInitializationService::ERROR_INITIALIZATION_NOT_ALLOWED_AFTER_HISTORY);
        app(PropertyBusinessDateInitializationService::class)->initialize($this->actor);
    }

    public function test_projection_rejects_incomplete_legacy_evidence_opening_evidence_and_timezone_mismatch(): void
    {
        PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-16',
            'timezone_snapshot' => null,
            'opened_by' => $this->actor->id,
            'opened_at' => now(),
        ]);

        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE);

        $this->createAuthorizedContext();
        PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-16',
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by' => null,
            'opened_at' => now(),
        ]);
        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE);

        $this->createAuthorizedContext();
        PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-16',
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by' => $this->actor->id,
            'opened_at' => null,
        ]);
        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_EVIDENCE_INCOMPLETE);

        $this->createAuthorizedContext();
        PropertyBusinessDate::factory()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-16',
            'timezone_snapshot' => 'Asia/Makassar',
            'opened_by' => $this->actor->id,
            'opened_at' => now(),
        ]);
        $this->property->forceFill(['timezone' => 'UTC'])->save();
        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_TIMEZONE_MISMATCH);
    }

    public function test_invalid_property_timezone_rejects_initialization_before_business_date_mutation(): void
    {
        $this->property->forceFill(['timezone' => 'Not/A_Zone'])->save();

        try {
            app(PropertyBusinessDateInitializationService::class)->initialize($this->actor);
            $this->fail('Invalid timezone must reject initialization.');
        } catch (RuntimeException $e) {
            $this->assertSame(PropertyBusinessDateInitializationService::ERROR_INVALID_TIMEZONE, $e->getMessage());
        }

        $this->assertSame(0, PropertyBusinessDate::withoutGlobalScopes()->count());
    }

    public function test_authorization_rejections_are_indistinguishable_and_before_business_date_queries(): void
    {
        $cases = [
            'missing_company' => fn () => session()->forget('active_company_id'),
            'unknown_company' => fn () => session(['active_company_id' => (string) Str::ulid()]),
            'cross_company' => fn () => session(['active_company_id' => \Database\Factories\CompanyFactory::new()->create(['is_active' => true])->id]),
            'missing_property_context' => function (): void {
                $this->actor = \Database\Factories\UserFactory::new()->create(['is_active' => true]);
                $this->actor->givePermissionTo([
                    PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
                    PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION,
                ]);
                auth()->login($this->actor);
                $this->actingAs($this->actor);
                app(CurrentPropertyService::class)->clear();
                session()->forget(['active_property_id', 'current_property_id']);
                session(['active_company_id' => $this->company->id]);
            },
            'unknown_property_context' => fn () => app(CurrentPropertyService::class)->setPropertyId((string) Str::ulid()),
            'inactive_property_context' => fn () => $this->property->forceFill(['is_active' => false])->save(),
            'cross_company_property_context' => function (): void {
                $otherCompany = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
                $otherProperty = \Database\Factories\PropertyFactory::new()->create([
                    'company_id' => $otherCompany->id,
                    'timezone' => 'Asia/Makassar',
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
                $this->actor->properties()->attach($otherProperty->id, [
                    'is_default' => false,
                    'status' => 'active',
                    'joined_at' => now(),
                ]);
                app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
            },
            'property_without_active_membership' => function (): void {
                $otherProperty = \Database\Factories\PropertyFactory::new()->create([
                    'company_id' => $this->company->id,
                    'timezone' => 'Asia/Makassar',
                    'currency' => 'USD',
                    'is_active' => true,
                ]);
                app(CurrentPropertyService::class)->setPropertyId($otherProperty->id);
            },
            'stale_active_property_id_alone' => function (): void {
                $this->actor = \Database\Factories\UserFactory::new()->create(['is_active' => true]);
                $this->actor->givePermissionTo([
                    PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
                    PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION,
                ]);
                auth()->login($this->actor);
                $this->actingAs($this->actor);
                app(CurrentPropertyService::class)->clear();
                session([
                    'active_company_id' => $this->company->id,
                    'active_property_id' => $this->property->id,
                ]);
                session()->forget('current_property_id');
            },
            'inactive_membership' => fn () => $this->actor->properties()->updateExistingPivot($this->property->id, ['status' => 'inactive']),
            'inactive_actor' => fn () => $this->actor->forceFill(['is_active' => false])->save(),
            'missing_view_permission' => fn () => $this->actor->revokePermissionTo(PropertyBusinessDateAuthorizationService::VIEW_PERMISSION),
            'missing_initialize_permission' => fn () => $this->actor->revokePermissionTo(PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION),
        ];

        foreach ($cases as $name => $mutate) {
            $this->createAuthorizedContext();
            $mutate();
            DB::flushQueryLog();
            DB::enableQueryLog();

            try {
                str_contains($name, 'initialize')
                    ? app(PropertyBusinessDateInitializationService::class)->initialize($this->actor)
                    : app(PropertyBusinessDateProjectionService::class)->project($this->actor);
                $this->fail("Authorization case {$name} must fail.");
            } catch (AuthorizationException $e) {
                $this->assertSame(PropertyBusinessDateAuthorizationService::FAILURE_MESSAGE, $e->getMessage());
            } catch (RuntimeException $e) {
                $this->fail("Authorization case {$name} reached Business Date logic: {$e->getMessage()}");
            }

            $this->assertNoBusinessDateOrForeignDomainQueries($name);
            DB::disableQueryLog();
        }
    }

    public function test_canonical_property_context_matrix_authorizes_only_trusted_resolver_tiers(): void
    {
        $this->createAuthorizedContext();
        $this->assertSame($this->property->id, app(PropertyBusinessDateAuthorizationService::class)->authorizeView($this->actor)->id);

        $this->createAuthorizedContext();
        app(CurrentPropertyService::class)->clear();
        session()->forget('active_property_id');
        session(['current_property_id' => $this->property->id]);
        $this->assertSame($this->property->id, app(PropertyBusinessDateAuthorizationService::class)->authorizeView($this->actor)->id);

        $this->createAuthorizedContext();
        app(CurrentPropertyService::class)->clear();
        session()->forget(['active_property_id', 'current_property_id']);
        $this->assertSame($this->property->id, app(PropertyBusinessDateAuthorizationService::class)->authorizeView($this->actor)->id);

        $this->createAuthorizedContext();
        $staleProperty = $this->authorizedSiblingProperty();
        app(CurrentPropertyService::class)->clear();
        session([
            'active_property_id' => $staleProperty->id,
            'current_property_id' => $this->property->id,
        ]);
        $this->assertSame($this->property->id, app(PropertyBusinessDateAuthorizationService::class)->authorizeView($this->actor)->id);

        $this->createAuthorizedContext();
        $staleProperty = $this->authorizedSiblingProperty();
        app(CurrentPropertyService::class)->setPropertyId($this->property->id);
        session([
            'active_property_id' => $staleProperty->id,
            'current_property_id' => $staleProperty->id,
        ]);
        $this->assertSame($this->property->id, app(PropertyBusinessDateAuthorizationService::class)->authorizeView($this->actor)->id);
    }

    public function test_projection_is_zero_write_and_exposes_no_later_lifecycle_behavior(): void
    {
        $row = app(PropertyBusinessDateInitializationService::class)->initialize($this->actor);
        $before = $this->tableCounts([
            'property_business_dates',
            'properties',
            'companies',
            'users',
            'property_user',
            'folios',
            'guest_payment_transactions',
            'guest_deposit_transactions',
            'guest_refund_transactions',
            'cashier_sessions',
            'cashbook_transactions',
            'journal_entries',
            'inventory_transactions',
            'front_desk_stays',
        ]);

        $projection = app(PropertyBusinessDateProjectionService::class)->project($this->actor);
        $after = $this->tableCounts(array_keys($before));

        $this->assertSame($before, $after);
        $this->assertSame($row->id, $projection['property_business_date_id']);
        $this->assertFalse(method_exists(PropertyBusinessDateInitializationService::class, 'close'));
        $this->assertFalse(method_exists(PropertyBusinessDateInitializationService::class, 'advance'));
        $this->assertFalse(method_exists(PropertyBusinessDateInitializationService::class, 'reopen'));
    }

    public function test_static_architecture_boundary_has_no_routes_controllers_requests_or_foreign_domain_imports(): void
    {
        foreach ([
            base_path('Modules/Foundation/Property/Services/PropertyBusinessDateInitializationService.php'),
            base_path('Modules/Foundation/Property/Services/PropertyBusinessDateProjectionService.php'),
        ] as $path) {
            $source = file_get_contents($path);
            $this->assertStringNotContainsString('Illuminate\Http\Request', $source);
            $this->assertStringNotContainsString('Modules\\Operations\\', $source);
            $this->assertStringNotContainsString('Modules\\Finance\\', $source);
            $this->assertStringNotContainsString('Property ID', $source);
            $this->assertStringNotContainsString('BusinessDateCloseService', $source);
            $this->assertStringNotContainsString('BusinessDateCloseExecutionService', $source);
            $this->assertStringNotContainsString('dispatch(', $source);
            $this->assertStringNotContainsString('ShouldQueue', $source);
        }

        $this->assertCount(0, glob(base_path('Modules/Foundation/Property/Http/Controllers/*BusinessDate*Controller.php')) ?: []);
        $routeFiles = glob(base_path('routes/*.php')) ?: [];
        foreach ($routeFiles as $routeFile) {
            $this->assertStringNotContainsString('PropertyBusinessDateInitializationService', file_get_contents($routeFile));
            $this->assertStringNotContainsString('PropertyBusinessDateProjectionService', file_get_contents($routeFile));
        }
    }

    public function test_internal_resolver_remains_zero_argument_and_fail_closed(): void
    {
        $method = new \ReflectionMethod(CurrentBusinessDateService::class, 'getActiveBusinessDate');
        $this->assertSame(0, $method->getNumberOfParameters());

        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_NOT_INITIALIZED);

        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $this->property->id,
            'business_date' => '2026-07-15',
        ]);
        $this->assertProjectionFails(PropertyBusinessDateProjectionService::ERROR_OPEN_UNAVAILABLE);
    }

    private function authenticate(User $actor, Company $company, Property $property): void
    {
        app(CurrentPropertyService::class)->clear();
        app(CurrentPropertyService::class)->setPropertyId($property->id);
        session()->forget(['active_property_id', 'current_property_id']);
        session([
            'active_company_id' => $company->id,
        ]);
        auth()->login($actor);
        $this->actingAs($actor);
    }

    private function createAuthorizedContext(): void
    {
        $this->company = \Database\Factories\CompanyFactory::new()->create(['is_active' => true]);
        $this->property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor = \Database\Factories\UserFactory::new()->withProperty($this->property)->create(['is_active' => true]);
        $this->actor->givePermissionTo([
            PropertyBusinessDateAuthorizationService::VIEW_PERMISSION,
            PropertyBusinessDateAuthorizationService::INITIALIZE_PERMISSION,
        ]);

        $this->authenticate($this->actor, $this->company, $this->property);
    }

    private function assertProjectionFails(string $message): void
    {
        try {
            app(PropertyBusinessDateProjectionService::class)->project($this->actor);
            $this->fail("Projection must fail with {$message}.");
        } catch (RuntimeException $e) {
            $this->assertSame($message, $e->getMessage());
        }
    }

    private function tableCounts(array $tables): array
    {
        $counts = [];
        foreach ($tables as $table) {
            if (Schema::hasTable($table)) {
                $counts[$table] = DB::table($table)->count();
            }
        }

        return $counts;
    }

    private function authorizedSiblingProperty(): Property
    {
        $property = \Database\Factories\PropertyFactory::new()->create([
            'company_id' => $this->company->id,
            'timezone' => 'Asia/Makassar',
            'currency' => 'USD',
            'is_active' => true,
        ]);
        $this->actor->properties()->attach($property->id, [
            'is_default' => false,
            'status' => 'active',
            'joined_at' => now(),
        ]);

        return $property;
    }

    private function assertNoBusinessDateOrForeignDomainQueries(string $case): void
    {
        $sql = implode("\n", array_column(DB::getQueryLog(), 'query'));

        foreach ([
            'property_business_dates',
            'front_desk',
            'folios',
            'guest_ledger',
            'guest_payment',
            'guest_deposit',
            'guest_refund',
            'cashier_sessions',
            'cashbook',
            'bank_',
            'journal_entries',
            'accounts_receivable',
            'inventory_',
            'housekeeping',
            'engineering',
        ] as $table) {
            $this->assertStringNotContainsString($table, $sql, $case);
        }
    }
}
