<?php

namespace Tests\Feature\SalesAndEventManagement;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\AccountContact;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\LostBusiness;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;
use Modules\SalesAndEventManagement\Enums\ContactRoleEnum;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Services\OpportunityGovernanceGuard;

class FoundationTest extends TestCase
{
    use RefreshDatabase;

    public function test_account_creation_at_company_level()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM Corporation',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $this->assertEquals('comp_1', $account->company_id);
        $this->assertEquals(AccountTypeEnum::Corporate, $account->account_type);
        $this->assertDatabaseHas('accounts', ['account_name' => 'IBM Corporation']);
    }

    public function test_account_contact_creation()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Marriott Planner',
            'account_type' => AccountTypeEnum::WeddingPlanner,
        ]);

        $contact = AccountContact::create([
            'account_id' => $account->id,
            'first_name' => 'John',
            'last_name' => 'Doe',
            'contact_role' => ContactRoleEnum::DecisionMaker,
            'email' => 'john@example.com',
        ]);

        $this->assertEquals($account->id, $contact->account_id);
        $this->assertEquals(ContactRoleEnum::DecisionMaker, $contact->contact_role);
    }

    public function test_opportunity_creation_at_property_level()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Global Events',
            'account_type' => AccountTypeEnum::EventOrganizer,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Global Events Annual Summit 2027',
            'status' => OpportunityStatusEnum::Qualified,
            'estimated_revenue' => 250000.00,
            'expected_event_date' => '2027-05-15',
        ]);

        $this->assertEquals('prop_1', $opportunity->property_id);
        $this->assertEquals(OpportunityStatusEnum::Qualified, $opportunity->status);
        $this->assertEquals(250000.00, $opportunity->estimated_revenue);
    }

    public function test_opportunity_governance_guard_property_isolation()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'IBM Retreat',
            'status' => OpportunityStatusEnum::Inquiry,
        ]);

        $guard = new OpportunityGovernanceGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cross-property or cross-company opportunity access is forbidden/');

        $guard->validateAccess($opportunity, 'comp_1', 'prop_2'); // Wrong property
    }

    public function test_opportunity_governance_guard_company_isolation()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $guard = new OpportunityGovernanceGuard();

        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Cross-company account access is forbidden/');

        $guard->validateAccountAccess($account, 'comp_2'); // Wrong company
    }

    public function test_lost_business_validation_success()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Microsoft',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Microsoft Gala',
            'status' => OpportunityStatusEnum::Negotiation,
        ]);

        $guard = new OpportunityGovernanceGuard();
        
        $guard->transitionToLost(
            $opportunity,
            'Price too high',
            'Hilton Downtown',
            '2026-06-15',
            150000.00,
            'Grand Ballroom'
        );

        $this->assertEquals(OpportunityStatusEnum::Lost, $opportunity->fresh()->status);
        
        $lostBusiness = LostBusiness::where('opportunity_id', $opportunity->id)->first();
        $this->assertNotNull($lostBusiness);
        $this->assertEquals('Price too high', $lostBusiness->lost_reason);
        $this->assertEquals('Hilton Downtown', $lostBusiness->lost_competitor);
        $this->assertEquals(150000.00, $lostBusiness->lost_price);
    }

    public function test_lost_business_validation_fails_missing_fields()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'Apple',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Apple Keynote',
            'status' => OpportunityStatusEnum::Negotiation,
        ]);

        $guard = new OpportunityGovernanceGuard();
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/lost_reason, lost_competitor, and lost_date are mandatory/');

        $guard->transitionToLost($opportunity, '', '', '');
    }
}
