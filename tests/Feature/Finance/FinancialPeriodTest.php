<?php

namespace Tests\Feature\Finance;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\Foundation\Property\Models\Property;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Enums\FinancialPeriodStatusEnum;
use Illuminate\Database\QueryException;
use Shared\Services\CurrentPropertyService;

class FinancialPeriodTest extends TestCase
{
    use RefreshDatabase;

    private CurrentPropertyService $currentPropertyService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->currentPropertyService = $this->app->make(CurrentPropertyService::class);
    }

    private function createProperty(): Property
    {
        $company = \Modules\Foundation\Property\Models\Company::first();
        if (!$company) {
            $company = new \Modules\Foundation\Property\Models\Company(['name' => 'Test Company', 'slug' => 'test-company-' . uniqid()]);
            $company->save();
        }
        $property = new Property(['name' => 'Test Property ' . uniqid(), 'slug' => 'test-property-' . uniqid(), 'code' => 'TST' . rand(10, 99), 'company_id' => $company->id]);
        $property->save();
        return $property;
    }

    public function test_persists_with_valid_enum_status()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        
        $fp = FinancialPeriod::create([
            'property_id' => $property->id,
            'period_year' => 2026,
            'period_month' => 6,
            'status' => 'Open',
        ]);
        
        $this->assertEquals(FinancialPeriodStatusEnum::Open, $fp->fresh()->status);
        $this->currentPropertyService->clear();
    }

    public function test_normal_scoped_behavior_filters_by_current_property()
    {
        $propertyA = $this->createProperty();
        $propertyB = $this->createProperty();

        // Seed via explicit property ID, overriding scope for creation
        $this->currentPropertyService->setPropertyId($propertyA->id);
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);
        
        $this->currentPropertyService->setPropertyId($propertyB->id);
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);

        // Resolve context to Property A
        $this->currentPropertyService->setPropertyId($propertyA->id);

        // Uses normal query path without explicit where('property_id', ...)
        $results = FinancialPeriod::all();
        
        $this->assertCount(1, $results);
        $this->assertEquals($propertyA->id, $results->first()->property_id);
        
        // Clear context
        $this->currentPropertyService->clear();
    }

    public function test_explicit_property_scope_behavior_respected()
    {
        $propertyA = $this->createProperty();
        $propertyB = $this->createProperty();

        $this->currentPropertyService->setPropertyId($propertyA->id);
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);
        $this->currentPropertyService->setPropertyId($propertyB->id);
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);

        $this->currentPropertyService->clear();

        $this->assertEquals(1, FinancialPeriod::withoutGlobalScope('property')->where('property_id', $propertyA->id)->count());
        $this->assertEquals(1, FinancialPeriod::withoutGlobalScope('property')->where('property_id', $propertyB->id)->count());
    }

    public function test_soft_deleted_excluded_from_normal_queries()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $fp = FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);
        
        $fp->delete();
        $this->assertEquals(0, FinancialPeriod::count());
        $this->currentPropertyService->clear();
    }

    public function test_soft_deleted_available_to_with_trashed()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        $fp = FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);
        
        $fp->delete();
        $this->assertEquals(1, FinancialPeriod::withTrashed()->count());
        $this->currentPropertyService->clear();
    }

    public function test_composite_schema_behavior_respected()
    {
        $property = $this->createProperty();
        $this->currentPropertyService->setPropertyId($property->id);
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Open']);
        
        $this->expectException(QueryException::class);
        $this->expectExceptionMessageMatches('/UNIQUE constraint failed|Integrity constraint violation|duplicate key|23505/i');
        
        FinancialPeriod::create(['period_year' => 2026, 'period_month' => 6, 'status' => 'Closed']);
    }
}
