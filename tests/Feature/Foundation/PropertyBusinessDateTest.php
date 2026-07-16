<?php

namespace Tests\Feature\Foundation;

use Tests\TestCase;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\Property\Models\PropertyBusinessDate;
use Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Shared\Services\CurrentPropertyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Database\QueryException;
use RuntimeException;

class PropertyBusinessDateTest extends TestCase
{
    use RefreshDatabase;

    private function createProperty(): Property
    {
        $company = \Modules\Foundation\Property\Models\Company::create([
            'name' => 'Test Company',
            'slug' => uniqid('comp-'),
        ]);

        return Property::create([
            'company_id' => $company->id,
            'name' => 'Test Property ' . uniqid(),
            'slug' => uniqid('prop-'),
            'code' => \Illuminate\Support\Str::random(5),
            'timezone' => 'UTC',
            'currency' => 'USD',
        ]);
    }

    public function test_property_a_and_property_b_can_have_different_active_business_dates()
    {
        $propertyA = $this->createProperty();
        $propertyB = $this->createProperty();

        $dateA = PropertyBusinessDate::factory()->create([
            'property_id' => $propertyA->id,
            'business_date' => '2026-06-01',
        ]);

        $dateB = PropertyBusinessDate::factory()->create([
            'property_id' => $propertyB->id,
            'business_date' => '2026-06-02',
        ]);

        $this->assertEquals('2026-06-01', $dateA->business_date->format('Y-m-d'));
        $this->assertEquals('2026-06-02', $dateB->business_date->format('Y-m-d'));
    }

    public function test_one_property_cannot_have_two_active_open_business_dates()
    {
        $property = $this->createProperty();

        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
            'is_open' => true,
        ]);

        $this->expectException(QueryException::class);

        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-02',
            'is_open' => true,
        ]);
    }

    public function test_one_property_can_have_multiple_closed_business_dates()
    {
        $property = $this->createProperty();

        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-02',
        ]);

        $this->assertEquals(2, PropertyBusinessDate::where('property_id', $property->id)->count());
    }

    public function test_closing_an_existing_open_date_allows_a_later_business_date_to_become_open()
    {
        $property = $this->createProperty();

        $date1 = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        // Close it
        $date1->update([
            'status' => \Modules\Foundation\Property\Enums\PropertyBusinessDateStatusEnum::Closed,
            'is_open' => null,
            'closed_at' => now(),
        ]);

        // Create new Open
        $date2 = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-02',
        ]);

        $this->assertEquals('2026-06-02', $date2->business_date->format('Y-m-d'));
    }

    public function test_resolver_uses_trusted_server_side_current_property_context()
    {
        $property = $this->createProperty();
        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        app(CurrentPropertyService::class)->setId($property->id);

        $resolver = app(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);
        $activeDate = $resolver->getActiveBusinessDate();

        $this->assertEquals('2026-06-01', $activeDate->business_date->format('Y-m-d'));
    }

    public function test_missing_business_date_fails_safely_with_distinguishable_exception()
    {
        $property = $this->createProperty();
        app(CurrentPropertyService::class)->setId($property->id);

        $resolver = app(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CurrentBusinessDateService::ERROR_NOT_INITIALIZED);

        $resolver->getActiveBusinessDate();
    }

    public function test_closed_business_date_fails_safely_with_business_logic_exception()
    {
        $property = $this->createProperty();
        PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        app(CurrentPropertyService::class)->setId($property->id);

        $resolver = app(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CurrentBusinessDateService::ERROR_OPEN_UNAVAILABLE);

        $resolver->getActiveBusinessDate();
    }

    public function test_cross_property_access_is_rejected()
    {
        $propertyA = $this->createProperty();
        $propertyB = $this->createProperty();

        PropertyBusinessDate::factory()->create([
            'property_id' => $propertyA->id,
            'business_date' => '2026-06-01',
        ]);

        app(CurrentPropertyService::class)->setId($propertyB->id);

        $resolver = app(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(CurrentBusinessDateService::ERROR_NOT_INITIALIZED);
        $resolver->getActiveBusinessDate();
    }

    public function test_property_timezone_is_taken_from_persisted_configuration()
    {
        $property = $this->createProperty();
        $property->update(['timezone' => 'Asia/Tokyo']);

        $this->assertEquals('Asia/Tokyo', $property->fresh()->timezone);

        $propertyB = $this->createProperty();
        $propertyB->update(['timezone' => 'America/New_York']);

        $this->assertEquals('America/New_York', $propertyB->fresh()->timezone);
    }

    public function test_status_is_open_inconsistent_persistence_is_rejected_open_null()
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not support ALTER TABLE ADD CONSTRAINT.');
        }

        $property = $this->createProperty();

        $this->expectException(QueryException::class);

        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
            'status' => 'Open',
            'is_open' => null, // Inconsistent!
        ]);
    }

    public function test_status_is_open_inconsistent_persistence_is_rejected_closed_true()
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not support ALTER TABLE ADD CONSTRAINT.');
        }

        $property = $this->createProperty();

        $this->expectException(QueryException::class);

        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
            'status' => 'Closed',
            'is_open' => true, // Inconsistent!
        ]);
    }

    public function test_status_is_open_inconsistent_persistence_is_rejected_closed_false()
    {
        if (\Illuminate\Support\Facades\DB::getDriverName() === 'sqlite') {
            $this->markTestSkipped('SQLite does not support ALTER TABLE ADD CONSTRAINT.');
        }

        $property = $this->createProperty();

        $this->expectException(QueryException::class);

        PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
            'status' => 'Closed',
            'is_open' => false, // Inconsistent!
        ]);
    }

    public function test_open_business_date_cannot_be_deleted()
    {
        $property = $this->createProperty();

        $date1 = PropertyBusinessDate::factory()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
            'timezone_snapshot' => 'UTC',
        ]);

        $this->assertEquals('UTC', $date1->timezone_snapshot);

        $this->expectException(\Shared\Exceptions\BusinessLogicException::class);
        $this->expectExceptionMessage("Business Dates cannot be deleted. They must be transitioned through the standard lifecycle.");

        $date1->delete();
    }

    public function test_closed_business_date_cannot_be_deleted()
    {
        $property = $this->createProperty();

        $date1 = PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        $this->expectException(\Shared\Exceptions\BusinessLogicException::class);
        $this->expectExceptionMessage("Business Dates cannot be deleted. They must be transitioned through the standard lifecycle.");

        $date1->forceDelete();
    }

    public function test_historical_closed_dates_remain_queryable()
    {
        $property = $this->createProperty();

        $date1 = PropertyBusinessDate::factory()->closed()->create([
            'property_id' => $property->id,
            'business_date' => '2026-06-01',
        ]);

        $this->assertNotNull(PropertyBusinessDate::where('property_id', $property->id)->where('status', 'Closed')->first());
    }
    public function test_missing_current_property_context_fails_safely_with_property_not_resolved_exception()
    {
        // We do not set the property ID in the CurrentPropertyService.
        $resolver = app(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);

        $this->expectException(\Shared\Exceptions\PropertyNotResolvedException::class);
        $this->expectExceptionMessage("Property context could not be resolved.");

        $resolver->getActiveBusinessDate();
    }

    public function test_resolver_contract_proves_no_public_method_accepts_arbitrary_inputs()
    {
        $reflection = new \ReflectionClass(\Modules\Foundation\Property\Services\CurrentBusinessDateService::class);
        $method = $reflection->getMethod('getActiveBusinessDate');

        // Prove that the method does not accept any parameters, therefore no caller can supply arbitrary
        // Property ID, Business Date, or timezone.
        $this->assertEquals(0, $method->getNumberOfParameters(), 'getActiveBusinessDate must not accept any arguments.');
    }
}
