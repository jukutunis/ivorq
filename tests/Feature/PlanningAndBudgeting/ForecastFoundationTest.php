<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\Forecast;
use Modules\PlanningAndBudgeting\Models\ForecastVersion;
use Modules\PlanningAndBudgeting\Models\ForecastEntry;
use Modules\PlanningAndBudgeting\Models\BudgetCategory;
use Modules\PlanningAndBudgeting\Enums\ForecastTypeEnum;
use Modules\PlanningAndBudgeting\Enums\ForecastSourceTypeEnum;
use Modules\PlanningAndBudgeting\Enums\ForecastStatusEnum;
use Modules\PlanningAndBudgeting\Enums\AccuracyStatusEnum;
use Modules\PlanningAndBudgeting\Enums\BudgetCategoryTypeEnum;
use Modules\PlanningAndBudgeting\Services\ForecastGovernanceGuard;
use Modules\PlanningAndBudgeting\Models\RevenueAssumption;
use Modules\PlanningAndBudgeting\Enums\RevenueMetricEnum;

class ForecastFoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_forecast_creation_with_enums()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Rolling Forecast Jun 2026',
            'forecast_type' => ForecastTypeEnum::Rolling,
            'forecast_source_type' => ForecastSourceTypeEnum::PmsPace,
        ]);

        $this->assertEquals(ForecastTypeEnum::Rolling, $forecast->forecast_type);
        $this->assertEquals(ForecastSourceTypeEnum::PmsPace, $forecast->forecast_source_type);
        $this->assertDatabaseHas('forecasts', ['forecast_name' => 'Rolling Forecast Jun 2026']);
    }

    public function test_forecast_version_creation_with_accuracy_readiness()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Rolling Forecast Jun 2026',
            'forecast_type' => ForecastTypeEnum::Rolling,
            'forecast_source_type' => ForecastSourceTypeEnum::PmsPace,
        ]);

        $version = ForecastVersion::create([
            'forecast_id' => $forecast->id,
            'version_number' => 1,
            'status' => ForecastStatusEnum::Draft,
            'accuracy_status' => AccuracyStatusEnum::Pending,
        ]);

        $this->assertEquals(AccuracyStatusEnum::Pending, $version->accuracy_status);
        $this->assertEquals(ForecastStatusEnum::Draft, $version->status);
    }

    public function test_shared_assumption_strategy()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Q2 Reforecast FY2026',
            'forecast_type' => ForecastTypeEnum::Reforecast,
            'forecast_source_type' => ForecastSourceTypeEnum::Manual,
        ]);

        $version = ForecastVersion::create([
            'forecast_id' => $forecast->id,
            'version_number' => 1,
            'status' => ForecastStatusEnum::Draft,
        ]);

        $assumption = RevenueAssumption::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_version_id' => $version->id,
            'metric_type' => RevenueMetricEnum::Occupancy,
            'period_number' => 1,
            'value' => 85.0,
        ]);

        $this->assertEquals($version->id, $assumption->forecastVersion->id);
        $this->assertNull($assumption->budget_version_id);
    }

    public function test_forecast_entry_mirrors_budget_entry()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Rolling Forecast',
            'forecast_type' => ForecastTypeEnum::Rolling,
            'forecast_source_type' => ForecastSourceTypeEnum::Manual,
        ]);

        $version = ForecastVersion::create([
            'forecast_id' => $forecast->id,
            'version_number' => 1,
            'status' => ForecastStatusEnum::Draft,
        ]);

        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Room Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $entry = ForecastEntry::create([
            'forecast_version_id' => $version->id,
            'budget_category_id' => $category->id,
            'forecastable_type' => 'Department',
            'forecastable_id' => 'dept_rooms',
            'period_number' => 1,
            'amount' => 120000.00,
        ]);

        $this->assertEquals(120000.00, $entry->amount);
        $this->assertEquals('Department', $entry->forecastable_type);
    }

    public function test_forecast_governance_guard_property_isolation()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Rolling Forecast',
            'forecast_type' => ForecastTypeEnum::Rolling,
            'forecast_source_type' => ForecastSourceTypeEnum::Manual,
        ]);

        $guard = new ForecastGovernanceGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cross-property or cross-company forecast access is forbidden/');

        $guard->validatePropertyAccess($forecast, 'comp_1', 'prop_2'); // Wrong property
    }

    public function test_forecast_governance_guard_immutability()
    {
        $forecast = Forecast::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'forecast_name' => 'Rolling Forecast',
            'forecast_type' => ForecastTypeEnum::Rolling,
            'forecast_source_type' => ForecastSourceTypeEnum::Manual,
        ]);

        $version = ForecastVersion::create([
            'forecast_id' => $forecast->id,
            'version_number' => 1,
            'status' => ForecastStatusEnum::Locked,
        ]);

        $guard = new ForecastGovernanceGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cannot modify a LOCKED forecast version. Version is immutable./');

        $guard->validateVersionModification($version);
    }
}
