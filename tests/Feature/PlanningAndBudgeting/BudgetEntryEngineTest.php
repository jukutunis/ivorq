<?php

namespace Tests\Feature\PlanningAndBudgeting;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\PlanningAndBudgeting\Models\BudgetCategory;
use Modules\PlanningAndBudgeting\Models\BudgetGLMapping;
use Modules\PlanningAndBudgeting\Models\BudgetEntry;
use Modules\PlanningAndBudgeting\Models\BudgetVersion;
use Modules\PlanningAndBudgeting\Enums\BudgetCategoryTypeEnum;
use Modules\PlanningAndBudgeting\Services\BudgetEntryGuard;
use Modules\PlanningAndBudgeting\Services\TranslationEngineFoundation;
use Modules\PlanningAndBudgeting\Models\RevenueAssumption;
use Illuminate\Support\Str;

class BudgetEntryEngineTest extends TestCase
{
    use RefreshDatabase;

    protected BudgetVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        
        $cycle = \Modules\PlanningAndBudgeting\Models\BudgetCycle::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'fiscal_year' => 'FY2027',
            'cycle_name' => 'Annual Budget',
            'start_date' => '2027-01-01',
            'end_date' => '2027-12-31',
            'status' => \Modules\PlanningAndBudgeting\Enums\BudgetCycleStatusEnum::Draft,
        ]);

        $scenario = \Modules\PlanningAndBudgeting\Models\BudgetScenario::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_cycle_id' => $cycle->id,
            'name' => 'Base',
        ]);

        $this->version = BudgetVersion::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_scenario_id' => $scenario->id,
            'version_number' => 1,
        ]);
    }

    public function test_budget_category_creation()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Room Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $this->assertEquals(BudgetCategoryTypeEnum::Revenue, $category->category_type);
        $this->assertDatabaseHas('budget_categories', ['category_name' => 'Room Revenue']);
    }

    public function test_budget_gl_mapping_creation()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Food Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $mapping = BudgetGLMapping::create([
            'company_id' => 'comp_1',
            'budget_category_id' => $category->id,
            'chart_of_account_id' => 'account_123',
        ]);

        $this->assertEquals($category->id, $mapping->budget_category_id);
        $this->assertDatabaseHas('budget_gl_mappings', ['chart_of_account_id' => 'account_123']);
    }

    public function test_budget_entry_creation_and_polymorphism()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Management Payroll',
            'category_type' => BudgetCategoryTypeEnum::Payroll,
        ]);

        $entry = BudgetEntry::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'budget_category_id' => $category->id,
            'budgetable_type' => 'Department',
            'budgetable_id' => 'dept_rooms',
            'period_number' => 1,
            'amount' => 50000.00,
        ]);

        $this->assertEquals('Department', $entry->budgetable_type);
        $this->assertEquals(50000.00, $entry->amount);
    }

    public function test_cost_center_cannot_receive_revenue_category()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Room Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $guard = new BudgetEntryGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Cost Centers cannot receive REVENUE categories./');

        $guard->validateCategoryAssignment('COST_CENTER', $category);
    }

    public function test_override_governance_enforces_reason_for_calculated_entries()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Room Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $entry = BudgetEntry::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'budget_category_id' => $category->id,
            'budgetable_type' => 'Department',
            'budgetable_id' => 'dept_rooms',
            'period_number' => 1,
            'amount' => 50000.00,
            'is_calculated' => true,
        ]);

        $guard = new BudgetEntryGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/GovernanceException: Override reason is required/');

        $guard->validateOverride($entry, 60000.00, null, 'user_1');
    }

    public function test_override_governance_success()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Room Revenue',
            'category_type' => BudgetCategoryTypeEnum::Revenue,
        ]);

        $entry = BudgetEntry::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'budget_category_id' => $category->id,
            'budgetable_type' => 'Department',
            'budgetable_id' => 'dept_rooms',
            'period_number' => 1,
            'amount' => 50000.00,
            'is_calculated' => true,
        ]);

        $guard = new BudgetEntryGuard();
        $guard->validateOverride($entry, 60000.00, 'Adjusted for market conditions', 'user_1');
        $entry->save();

        $this->assertEquals(60000.00, $entry->amount);
        $this->assertEquals('Adjusted for market conditions', $entry->override_reason);
        $this->assertEquals('user_1', $entry->override_by);
        $this->assertNotNull($entry->override_at);
    }

    public function test_translation_engine_foundation_exists()
    {
        $engine = new TranslationEngineFoundation();
        $this->assertTrue(method_exists($engine, 'translateRevenueAssumptions'));
        $this->assertTrue(method_exists($engine, 'translateOperationalAssumptions'));
        $this->assertTrue(method_exists($engine, 'translateLaborAssumptions'));
    }

    public function test_property_isolation_on_budget_entries()
    {
        $category = BudgetCategory::create([
            'company_id' => 'comp_1',
            'category_name' => 'Food Cost',
            'category_type' => BudgetCategoryTypeEnum::Expense,
        ]);

        BudgetEntry::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'budget_version_id' => $this->version->id,
            'budget_category_id' => $category->id,
            'budgetable_type' => 'Department',
            'budgetable_id' => 'dept_fb',
            'period_number' => 1,
            'amount' => 1000.00,
        ]);

        BudgetEntry::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_2',
            'budget_version_id' => $this->version->id,
            'budget_category_id' => $category->id,
            'budgetable_type' => 'Department',
            'budgetable_id' => 'dept_fb',
            'period_number' => 1,
            'amount' => 2000.00,
        ]);

        $prop1Entries = BudgetEntry::where('property_id', 'prop_1')->get();
        
        $this->assertCount(1, $prop1Entries);
        $this->assertEquals(1000.00, $prop1Entries->first()->amount);
    }
}
