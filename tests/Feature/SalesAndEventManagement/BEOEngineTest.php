<?php

namespace Tests\Feature\SalesAndEventManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;
use Modules\SalesAndEventManagement\Models\EventFunction;
use Modules\SalesAndEventManagement\Models\Event;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Enums\BEOStatusEnum;
use Modules\SalesAndEventManagement\Services\IssueBEOAction;
use Modules\SalesAndEventManagement\Services\BEOGovernanceGuard;
use Modules\SalesAndEventManagement\Services\BEONumberGenerator;
use Modules\Foundation\Department\Models\Department;
use Illuminate\Support\Str;

class BEOEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create actual Company and Property for relations
        $company = \Modules\Foundation\Property\Models\Company::create([
            'name' => 'Test Company',
            'slug' => 'test-company'
        ]);
        
        $property = \Modules\Foundation\Property\Models\Property::create([
            'company_id' => $company->id,
            'name' => 'Test Property',
            'slug' => 'test-property',
            'code' => 'TESTPROP'
        ]);

        $this->companyId = $company->id;
        $this->propertyId = $property->id;
        
        app(\Shared\Services\CurrentPropertyService::class)->setPropertyId($this->propertyId);
        
        // Let's create dummy prereq data
        $account = Account::create(['account_name' => 'Test Account', 'company_id' => $this->companyId, 'account_type' => 'CORPORATE']);
        
        $this->opportunity = Opportunity::create([
            'account_id' => $account->id,
            'opportunity_name' => 'Test Opp',
            'status' => 'INQUIRY',
            'opportunity_source' => 'WEBSITE',
            'company_id' => $this->companyId,
            'property_id' => $this->propertyId
        ]);

        $this->event = Event::create([
            'opportunity_id' => $this->opportunity->id,
            'event_name' => 'Test Event',
            'status' => 'TENTATIVE',
            'event_type' => 'CONFERENCE',
        ]);

        $this->function = EventFunction::create([
            'event_id' => $this->event->id,
            'function_name' => 'Lunch',
            'status' => 'PLANNED',
        ]);

        $this->department = Department::create([
            'company_id' => $this->companyId,
            'property_id' => $this->propertyId,
            'name' => 'Kitchen',
            'code' => 'KITCHEN',
            'type' => 'operational'
        ]);

        $this->action = new IssueBEOAction(new BEOGovernanceGuard(), new BEONumberGenerator());
    }

    public function test_it_creates_issue_and_generates_hash()
    {
        $beo = $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_creator',
            'user_approver'
        );

        $this->assertNotNull($beo->id);
        $this->assertNotNull($beo->snapshot_hash);
        $this->assertIsArray($beo->snapshot_payload);
        $this->assertEquals(0, $beo->revision_number);
        $this->assertEquals(BEOStatusEnum::PUBLISHED, $beo->status);
    }

    public function test_it_handles_revision_chain_and_supersedes_behavior()
    {
        $original = $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_creator',
            'user_approver'
        );

        $revision = $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_creator2',
            'user_approver2'
        );

        $original->refresh();

        $this->assertEquals(1, $revision->revision_number);
        $this->assertEquals($original->id, $revision->previous_issue_id);
        $this->assertEquals(BEOStatusEnum::SUPERSEDED, $original->status);
    }

    public function test_it_generates_acknowledgement_requests()
    {
        $beo = $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_creator',
            'user_approver',
            [$this->department->id]
        );

        $this->assertCount(1, $beo->acknowledgements);
        $this->assertEquals($this->department->id, $beo->acknowledgements->first()->department_id);
    }

    public function test_governance_guard_enforces_property_isolation()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Property isolation breached');

        $this->action->execute(
            $this->function,
            $this->companyId,
            'WRONG_PROPERTY',
            'BLI',
            'user_creator',
            'user_approver'
        );
    }

    public function test_governance_guard_enforces_creator_not_approver()
    {
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Creator cannot approve their own BEO');

        $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_same',
            'user_same'
        );
    }

    public function test_governance_guard_enforces_immutability()
    {
        $beo = $this->action->execute(
            $this->function,
            $this->companyId,
            $this->propertyId,
            'BLI',
            'user_creator',
            'user_approver'
        );

        $guard = new BEOGovernanceGuard();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessage('Cannot modify an issued or superseded BEO');
        
        $guard->enforceImmutability($beo);
    }

    public function test_number_generation_format()
    {
        $generator = new BEONumberGenerator();
        $number = $generator->generate($this->function, 'BLI', 2);
        
        // Format should be BEO-BLI-YYYY-F000000-R2
        $year = date('Y');
        $this->assertMatchesRegularExpression('/^BEO-BLI-' . $year . '-F[0-9]{6}-R2$/', $number);
    }
}
