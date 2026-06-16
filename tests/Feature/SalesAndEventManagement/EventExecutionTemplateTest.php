<?php

namespace Tests\Feature\SalesAndEventManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\Property\Models\Property;
use Modules\SalesAndEventManagement\Enums\EventExecutionTemplateCategoryEnum;
use Modules\SalesAndEventManagement\Enums\OperationalPackageTypeEnum;
use Modules\SalesAndEventManagement\Enums\RevenueClassificationEnum;
use Modules\SalesAndEventManagement\Enums\TaskPriorityEnum;
use Modules\SalesAndEventManagement\Enums\TemplateStatusEnum;
use Modules\SalesAndEventManagement\Enums\VenueTypeEnum;
use Modules\SalesAndEventManagement\Models\EventExecutionTemplate;
use Modules\SalesAndEventManagement\Models\OperationalPackage;
use Modules\SalesAndEventManagement\Models\TaskSection;
use Modules\SalesAndEventManagement\Models\VenueSetupSection;
use Modules\SalesAndEventManagement\Services\TemplateCloningService;
use Modules\SalesAndEventManagement\Services\TemplateGovernanceGuard;
use Tests\TestCase;

class EventExecutionTemplateTest extends TestCase
{
    use RefreshDatabase;

    private Company $company;
    private Property $property;
    private Department $department;

    protected function setUp(): void
    {
        parent::setUp();

        $this->company = Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company',
            'status' => 'ACTIVE'
        ]);

        $this->property = Property::create([
            'company_id' => $this->company->id,
            'name' => 'Test Property',
            'code' => 'TST',
            'slug' => 'test-property',
            'status' => 'ACTIVE'
        ]);

        $this->department = Department::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'name' => 'Banquets',
            'code' => 'BANQ',
            'is_active' => true
        ]);
    }

    public function test_it_can_create_company_and_property_templates()
    {
        $companyTemplate = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Corporate Base',
            'category' => EventExecutionTemplateCategoryEnum::Conference,
            'status' => TemplateStatusEnum::Draft,
            'created_by' => 'user-1'
        ]);

        $this->assertNull($companyTemplate->property_id);

        $propertyTemplate = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'name' => 'Local Override',
            'category' => EventExecutionTemplateCategoryEnum::Meeting,
            'status' => TemplateStatusEnum::Draft,
            'created_by' => 'user-2'
        ]);

        $this->assertNotNull($propertyTemplate->property_id);
    }

    public function test_it_can_create_packages_and_all_sections()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Test',
            'category' => EventExecutionTemplateCategoryEnum::Wedding,
            'status' => TemplateStatusEnum::Draft,
            'created_by' => 'user-1'
        ]);

        $package = $template->operationalPackages()->create([
            'name' => 'Standard Package',
            'package_type' => OperationalPackageTypeEnum::Wedding,
            'revenue_classification' => RevenueClassificationEnum::PackageRevenue,
            'is_active' => true,
        ]);

        $this->assertNotNull($package->id);

        $package->venueSetupSections()->create([
            'venue_type' => VenueTypeEnum::Ballroom,
            'setup_style' => 'Rounds',
            'expected_capacity' => 200,
        ]);

        $package->fbSections()->create([
            'meal_type' => 'Dinner',
            'menu_description' => 'Plated 3 course'
        ]);

        $package->avSections()->create([
            'equipment_required' => 'Projector',
            'technician_required' => true
        ]);

        $package->billingSections()->create([
            'deposit_schedule' => '50% on signing',
        ]);

        $package->specialRequestSections()->create([
            'request_details' => 'No peanuts'
        ]);

        $package->taskSections()->create([
            'task_name' => 'Setup Dance Floor',
            'priority' => TaskPriorityEnum::High,
            'department_id' => $this->department->id,
            'due_offset_minutes' => 120
        ]);

        $package->staffingSections()->create([
            'role_name' => 'Server',
            'department_id' => $this->department->id,
            'headcount' => 10,
            'shift_duration_hours' => 8
        ]);

        $package->load(['venueSetupSections', 'fbSections', 'avSections', 'billingSections', 'specialRequestSections', 'taskSections', 'staffingSections']);
        
        $this->assertCount(1, $package->venueSetupSections);
        $this->assertCount(1, $package->fbSections);
        $this->assertCount(1, $package->avSections);
        $this->assertCount(1, $package->billingSections);
        $this->assertCount(1, $package->specialRequestSections);
        $this->assertCount(1, $package->taskSections);
        $this->assertCount(1, $package->staffingSections);
    }

    public function test_creator_cannot_be_approver()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Draft',
            'category' => EventExecutionTemplateCategoryEnum::Banquet,
            'status' => TemplateStatusEnum::Draft,
        ]);
        $template->created_by = 'user-1';
        $template->saveQuietly();

        $guard = new TemplateGovernanceGuard();

        $this->expectException(ValidationException::class);
        $guard->enforceCreatorIsNotApprover($template, 'user-1');
    }

    public function test_published_templates_are_immutable()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Published',
            'category' => EventExecutionTemplateCategoryEnum::Banquet,
            'status' => TemplateStatusEnum::Published,
            'created_by' => 'user-1'
        ]);

        $guard = new TemplateGovernanceGuard();

        $this->expectException(ValidationException::class);
        $guard->enforceImmutability($template);
    }

    public function test_property_isolation_validation()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'property_id' => $this->property->id,
            'name' => 'Property Template',
            'category' => EventExecutionTemplateCategoryEnum::Banquet,
            'status' => TemplateStatusEnum::Draft,
            'created_by' => 'user-1'
        ]);

        $guard = new TemplateGovernanceGuard();

        // Valid
        $guard->enforcePropertyIsolation($template, $this->property->id);

        $this->expectException(ValidationException::class);
        $guard->enforcePropertyIsolation($template, 'other-property-id');
    }

    public function test_revision_clone_bumps_version_and_supersedes()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'V1',
            'category' => EventExecutionTemplateCategoryEnum::Banquet,
            'status' => TemplateStatusEnum::Published,
            'revision_number' => 1,
            'created_by' => 'user-1'
        ]);

        $service = new TemplateCloningService(new TemplateGovernanceGuard());
        
        $newTemplate = $service->createRevision($template, 'user-2');

        $this->assertEquals(2, $newTemplate->revision_number);
        $this->assertEquals(TemplateStatusEnum::Draft, $newTemplate->status);
        $this->assertEquals($template->id, $newTemplate->previous_template_id);

        $template->refresh();
        $this->assertEquals(TemplateStatusEnum::Superseded, $template->status);
    }

    public function test_corporate_clone_replicates_to_property()
    {
        $template = EventExecutionTemplate::create([
            'company_id' => $this->company->id,
            'name' => 'Base Corporate',
            'category' => EventExecutionTemplateCategoryEnum::Banquet,
            'status' => TemplateStatusEnum::Published,
            'created_by' => 'user-1'
        ]);

        $package = $template->operationalPackages()->create([
            'name' => 'Standard Package',
            'package_type' => OperationalPackageTypeEnum::Banquet,
            'revenue_classification' => RevenueClassificationEnum::PackageRevenue,
        ]);

        $package->taskSections()->create([
            'task_name' => 'Setup Dance Floor',
            'priority' => TaskPriorityEnum::High,
            'department_id' => $this->department->id,
        ]);

        $service = new TemplateCloningService(new TemplateGovernanceGuard());
        
        $newTemplate = $service->cloneCorporateToProperty($template, $this->property->id, 'user-2');

        $this->assertEquals($this->property->id, $newTemplate->property_id);
        $this->assertEquals(TemplateStatusEnum::Draft, $newTemplate->status);
        $this->assertEquals(0, $newTemplate->revision_number);

        $newTemplate->load('operationalPackages.taskSections');
        $this->assertCount(1, $newTemplate->operationalPackages);
        $this->assertCount(1, $newTemplate->operationalPackages->first()->taskSections);
        $this->assertNotEquals($package->id, $newTemplate->operationalPackages->first()->id);
    }
}
