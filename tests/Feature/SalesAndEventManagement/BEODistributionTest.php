<?php

namespace Tests\Feature\SalesAndEventManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Modules\SalesAndEventManagement\Services\BEODistributionService;
use Modules\SalesAndEventManagement\Services\AcknowledgementEngine;
use Modules\SalesAndEventManagement\Services\DistributionEscalationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BEODistributionTest extends TestCase
{
    use RefreshDatabase;

    protected function createDummyIssueLog(string $companyId, string $propertyId): BEOIssueLog
    {
        $accountId = (string) Str::ulid();
        $opportunityId = (string) Str::ulid();
        $eventId = (string) Str::ulid();
        $functionId = (string) Str::ulid();

        // Create foundation schema elements safely if missing due to test db reset
        if (!DB::table('companies')->where('id', $companyId)->exists() && Schema::hasTable('companies')) {
            DB::table('companies')->insert(['id' => $companyId, 'name' => 'Company', 'slug' => 'comp-'.Str::random(6)]);
        }
        if (!DB::table('properties')->where('id', $propertyId)->exists() && Schema::hasTable('properties')) {
            DB::table('properties')->insert(['id' => $propertyId, 'company_id' => $companyId, 'name' => 'Property', 'slug' => 'prop-'.Str::random(6), 'code' => Str::random(3)]);
        }
        
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')->insert([
                'id' => $accountId,
                'company_id' => $companyId,
                'account_name' => 'Test Account',
                'account_type' => 'CORPORATE',
            ]);
        }

        DB::table('opportunities')->insert([
            'id' => $opportunityId,
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'account_id' => $accountId,
            'opportunity_name' => 'Test Opp',
            'status' => 'PROSPECT',
        ]);
        
        DB::table('events')->insert([
            'id' => $eventId,
            'opportunity_id' => $opportunityId,
            'event_name' => 'Test',
            'status' => 'DRAFT',
            'event_type' => 'CORPORATE',
        ]);
        
        DB::table('event_functions')->insert([
            'id' => $functionId,
            'event_id' => $eventId,
            'function_name' => 'Test',
            'status' => 'DRAFT',
        ]);

        return BEOIssueLog::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $companyId,
            'property_id' => $propertyId,
            'function_id' => $functionId,
            'issue_number' => 'BEO-001',
            'status' => 'PUBLISHED',
            'snapshot_payload' => json_encode(['foo' => 'bar']),
            'snapshot_hash' => 'hash123',
        ]);
    }

    public function test_distribution_creation_and_property_isolation()
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();

        $issueLog = $this->createDummyIssueLog($companyId, $propertyId);

        $service = new BEODistributionService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MAJOR);

        $this->assertEquals(DistributionStatusEnum::DRAFT, $distribution->status);
        $this->assertEquals(DistributionSeverityEnum::MAJOR, $distribution->severity);
        $this->assertEquals($companyId, $distribution->company_id);
        $this->assertEquals($propertyId, $distribution->property_id);
    }

    public function test_distribution_acknowledgement_lifecycle()
    {
        $companyId = (string) Str::ulid();
        $propertyId = (string) Str::ulid();

        $issueLog = $this->createDummyIssueLog($companyId, $propertyId);

        $service = new BEODistributionService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);

        $dept1 = (string) Str::ulid();
        $dept2 = (string) Str::ulid();
        
        $distribution = $service->distributeBEO($distribution->id, (string) Str::ulid(), [$dept1, $dept2]);
        
        $this->assertEquals(DistributionStatusEnum::DISTRIBUTED, $distribution->status);
        $this->assertCount(2, $distribution->acknowledgements);

        $ackEngine = new AcknowledgementEngine();
        $ack1 = $distribution->acknowledgements->first();
        $ack2 = $distribution->acknowledgements->last();

        // View
        $ack1 = $ackEngine->markAsViewed($ack1->id);
        $this->assertEquals(AcknowledgementStatusEnum::VIEWED, $ack1->status);

        // Acknowledge partially
        $ack1 = $ackEngine->acknowledge($ack1->id, (string) Str::ulid());
        $this->assertEquals(AcknowledgementStatusEnum::ACKNOWLEDGED, $ack1->status);
        
        $distribution->refresh();
        $this->assertEquals(DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED, $distribution->status);

        // Reject second one
        $ack2 = $ackEngine->reject($ack2->id, (string) Str::ulid(), "Missing info");
        $this->assertEquals(AcknowledgementStatusEnum::REJECTED, $ack2->status);
        $this->assertEquals("Missing info", $ack2->rejection_reason);
    }

    public function test_escalation_generation()
    {
        $issueLog = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());

        $distribution = BEODistribution::forceCreate([
            'id' => (string) Str::ulid(),
            'company_id' => $issueLog->company_id,
            'property_id' => $issueLog->property_id,
            'beo_issue_log_id' => $issueLog->id,
            'status' => DistributionStatusEnum::DISTRIBUTED,
            'severity' => DistributionSeverityEnum::MINOR,
        ]);

        $ack = BEOAcknowledgement::forceCreate([
            'id' => (string) Str::ulid(),
            'beo_distribution_id' => $distribution->id,
            'department_id' => (string) Str::ulid(),
            'status' => AcknowledgementStatusEnum::PENDING,
            'sla_hours_configured' => 24,
            'sla_breach_at' => now()->subHour(), // breached an hour ago
        ]);

        $escalationService = new DistributionEscalationService();
        $escalations = $escalationService->detectAndEscalateBreaches();

        $this->assertCount(1, $escalations);
        
        $ack->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::ESCALATED, $ack->status);
    }
}
