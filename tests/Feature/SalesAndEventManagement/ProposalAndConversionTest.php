<?php

namespace Tests\Feature\SalesAndEventManagement;

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Modules\SalesAndEventManagement\Models\Account;
use Modules\SalesAndEventManagement\Models\Opportunity;
use Modules\SalesAndEventManagement\Models\Proposal;
use Modules\SalesAndEventManagement\Models\ProposalRevision;
use Modules\SalesAndEventManagement\Enums\AccountTypeEnum;
use Modules\SalesAndEventManagement\Enums\OpportunityStatusEnum;
use Modules\SalesAndEventManagement\Enums\OpportunitySourceEnum;
use Modules\SalesAndEventManagement\Services\ProposalGovernanceGuard;
use Modules\SalesAndEventManagement\Services\OpportunityConversionFoundation;

class ProposalAndConversionTest extends TestCase
{
    use RefreshDatabase;

    public function test_opportunity_source_enum_assignment()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM Corporation',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Annual Summit',
            'status' => OpportunityStatusEnum::Inquiry,
            'opportunity_source' => OpportunitySourceEnum::Website,
        ]);

        $this->assertEquals(OpportunitySourceEnum::Website, $opportunity->opportunity_source);
    }

    public function test_proposal_revision_immutability_and_generation()
    {
        $account = Account::create([
            'company_id' => 'comp_1',
            'account_name' => 'IBM Corporation',
            'account_type' => AccountTypeEnum::Corporate,
        ]);

        $opportunity = Opportunity::create([
            'company_id' => 'comp_1',
            'property_id' => 'prop_1',
            'account_id' => $account->id,
            'opportunity_name' => 'Annual Summit',
            'status' => OpportunityStatusEnum::ProposalSent,
        ]);

        $proposal = Proposal::create([
            'opportunity_id' => $opportunity->id,
            'created_by' => 'user_1',
        ]);

        $guard = new ProposalGovernanceGuard();

        $rev1 = $guard->createRevision($proposal, 'Initial Draft Details', 'user_1');
        $this->assertEquals(1, $rev1->revision_number);
        $this->assertEquals('Initial Draft Details', $rev1->details);

        $rev2 = $guard->createRevision($proposal, 'Client requested 10% discount', 'user_1');
        $this->assertEquals(2, $rev2->revision_number);
        $this->assertEquals('Client requested 10% discount', $rev2->details);

        $this->assertCount(2, $proposal->revisions);
        
        $this->expectException(\Exception::class);
        $this->expectExceptionMessageMatches('/Proposal Revisions are immutable and cannot be modified once generated/');

        $guard->validateImmutability($rev1);
    }

    public function test_opportunity_conversion_foundation_exists()
    {
        $foundation = new OpportunityConversionFoundation();
        
        $this->assertTrue(method_exists($foundation, 'convertToEvent'));
    }
}
