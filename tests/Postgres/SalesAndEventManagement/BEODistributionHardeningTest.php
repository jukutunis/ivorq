<?php

namespace Tests\Postgres\SalesAndEventManagement;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\PostgresTestCase;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Modules\SalesAndEventManagement\Services\BEODistributionService;
use Modules\SalesAndEventManagement\Services\AcknowledgementEngine;
use Modules\SalesAndEventManagement\Services\DistributionEscalationService;
use Modules\SalesAndEventManagement\Services\DistributionStateMachine;
use Modules\SalesAndEventManagement\Exceptions\DistributionStateException;
use Modules\SalesAndEventManagement\Events\DistributionDistributedEvent;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgedEvent;
use Modules\SalesAndEventManagement\Events\DistributionCompletedEvent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class BEODistributionHardeningTest extends PostgresTestCase
{
    use RefreshDatabase;

    // -----------------------------------------------------------------------
    // Fixture helpers
    // -----------------------------------------------------------------------

    protected function createDummyIssueLog(string $companyId, string $propertyId): BEOIssueLog
    {
        $accountId     = (string) Str::ulid();
        $opportunityId = (string) Str::ulid();
        $eventId       = (string) Str::ulid();
        $functionId    = (string) Str::ulid();

        if (! DB::table('companies')->where('id', $companyId)->exists()) {
            DB::table('companies')->insert([
                'id'   => $companyId,
                'name' => 'Company',
                'slug' => 'comp-' . Str::random(6),
            ]);
        }
        if (! DB::table('properties')->where('id', $propertyId)->exists()) {
            DB::table('properties')->insert([
                'id'         => $propertyId,
                'company_id' => $companyId,
                'name'       => 'Property',
                'slug'       => 'prop-' . Str::random(6),
                'code'       => Str::random(3),
            ]);
        }
        if (Schema::hasTable('accounts')) {
            DB::table('accounts')->insert([
                'id'           => $accountId,
                'company_id'   => $companyId,
                'account_name' => 'Test Account',
                'account_type' => 'CORPORATE',
            ]);
        }

        DB::table('opportunities')->insert([
            'id'               => $opportunityId,
            'company_id'       => $companyId,
            'property_id'      => $propertyId,
            'account_id'       => $accountId,
            'opportunity_name' => 'Test Opp',
            'status'           => 'PROSPECT',
        ]);
        DB::table('events')->insert([
            'id'             => $eventId,
            'opportunity_id' => $opportunityId,
            'event_name'     => 'Test',
            'status'         => 'DRAFT',
            'event_type'     => 'CORPORATE',
        ]);
        DB::table('event_functions')->insert([
            'id'            => $functionId,
            'event_id'      => $eventId,
            'function_name' => 'Test',
            'status'        => 'DRAFT',
        ]);

        return BEOIssueLog::forceCreate([
            'id'               => (string) Str::ulid(),
            'company_id'       => $companyId,
            'property_id'      => $propertyId,
            'function_id'      => $functionId,
            'issue_number'     => 'BEO-' . Str::random(4),
            'revision_number'  => 0,
            'status'           => 'PUBLISHED',
            'snapshot_payload' => json_encode(['foo' => 'bar']),
            'snapshot_hash'    => 'hash123',
        ]);
    }

    private function makeService(): BEODistributionService
    {
        return new BEODistributionService(new DistributionStateMachine());
    }

    // -----------------------------------------------------------------------
    // Happy-path: creation inherits company/property isolation
    // -----------------------------------------------------------------------

    public function test_distribution_creation_and_property_isolation(): void
    {
        $companyId  = (string) Str::ulid();
        $propertyId = (string) Str::ulid();

        $issueLog     = $this->createDummyIssueLog($companyId, $propertyId);
        $distribution = $this->makeService()->createDistribution($issueLog->id, DistributionSeverityEnum::MAJOR);

        $this->assertEquals(DistributionStatusEnum::DRAFT, $distribution->status);
        $this->assertEquals(DistributionSeverityEnum::MAJOR, $distribution->severity);
        $this->assertEquals($companyId, $distribution->company_id);
        $this->assertEquals($propertyId, $distribution->property_id);
    }

    // -----------------------------------------------------------------------
    // Happy-path: full acknowledgement lifecycle
    // -----------------------------------------------------------------------

    public function test_distribution_acknowledgement_lifecycle(): void
    {
        Event::fake([
            DistributionDistributedEvent::class,
            DistributionAcknowledgedEvent::class,
            DistributionCompletedEvent::class,
        ]);

        $companyId  = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $issueLog   = $this->createDummyIssueLog($companyId, $propertyId);

        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);
        $dept1        = (string) Str::ulid();
        $dept2        = (string) Str::ulid();

        $distribution = $service->distributeBEO($distribution->id, (string) Str::ulid(), [$dept1, $dept2]);

        $this->assertEquals(DistributionStatusEnum::DISTRIBUTED, $distribution->status);
        $this->assertCount(2, $distribution->acknowledgements);
        Event::assertDispatched(DistributionDistributedEvent::class);

        $ackEngine = new AcknowledgementEngine();
        $ack1      = $distribution->acknowledgements->first();
        $ack2      = $distribution->acknowledgements->last();

        // View
        $ack1 = $ackEngine->markAsViewed($ack1->id);
        $this->assertEquals(AcknowledgementStatusEnum::VIEWED, $ack1->status);

        // Acknowledge partially
        $ack1 = $ackEngine->acknowledge($ack1->id, (string) Str::ulid());
        $this->assertEquals(AcknowledgementStatusEnum::ACKNOWLEDGED, $ack1->status);
        $distribution->refresh();
        $this->assertEquals(DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED, $distribution->status);

        // Reject second
        $ack2 = $ackEngine->reject($ack2->id, (string) Str::ulid(), 'Missing info');
        $this->assertEquals(AcknowledgementStatusEnum::REJECTED, $ack2->status);
        $this->assertEquals('Missing info', $ack2->rejection_reason);
    }

    // -----------------------------------------------------------------------
    // Happy-path: SLA escalation detected and recorded
    // -----------------------------------------------------------------------

    public function test_escalation_generation(): void
    {
        $issueLog = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());

        $distribution = BEODistribution::forceCreate([
            'id'               => (string) Str::ulid(),
            'company_id'       => $issueLog->company_id,
            'property_id'      => $issueLog->property_id,
            'beo_issue_log_id' => $issueLog->id,
            'status'           => DistributionStatusEnum::DISTRIBUTED,
            'severity'         => DistributionSeverityEnum::MINOR,
        ]);

        $ack = BEOAcknowledgement::forceCreate([
            'id'                   => (string) Str::ulid(),
            'beo_distribution_id'  => $distribution->id,
            'department_id'        => (string) Str::ulid(),
            'status'               => AcknowledgementStatusEnum::PENDING,
            'sla_hours_configured' => 24,
            'sla_breach_at'        => now()->subHour(),
        ]);

        $escalations = (new DistributionEscalationService())->detectAndEscalateBreaches();

        $this->assertCount(1, $escalations);
        $ack->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::ESCALATED, $ack->status);
    }

    // -----------------------------------------------------------------------
    // Guard: state machine blocks illegal transitions
    // -----------------------------------------------------------------------

    public function test_state_machine_blocks_illegal_transition(): void
    {
        $this->expectException(DistributionStateException::class);
        (new DistributionStateMachine())->guard(
            DistributionStatusEnum::DRAFT,
            DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED
        );
    }

    public function test_state_machine_blocks_transition_from_terminal_state(): void
    {
        $this->expectException(DistributionStateException::class);
        (new DistributionStateMachine())->guard(
            DistributionStatusEnum::COMPLETED,
            DistributionStatusEnum::DISTRIBUTED
        );
    }

    public function test_state_machine_blocks_superseded_to_distributed(): void
    {
        $this->expectException(DistributionStateException::class);
        (new DistributionStateMachine())->guard(
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::DISTRIBUTED
        );
    }

    public function test_distribute_non_draft_throws(): void
    {
        $this->expectException(DistributionStateException::class);

        $issueLog     = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());
        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);
        $distribution->update(['status' => DistributionStatusEnum::DISTRIBUTED]);

        $service->distributeBEO($distribution->id, (string) Str::ulid(), [(string) Str::ulid()]);
    }

    // -----------------------------------------------------------------------
    // Guard: distribute requires at least one department
    // -----------------------------------------------------------------------

    public function test_distribute_requires_at_least_one_department(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $issueLog     = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());
        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);

        $service->distributeBEO($distribution->id, (string) Str::ulid(), []);
    }

    // -----------------------------------------------------------------------
    // Guard: supersede cascade closes orphaned acknowledgements (Sprint 14.8.5.1 §4)
    // -----------------------------------------------------------------------

    public function test_supersede_cascade_closes_orphaned_acknowledgements(): void
    {
        $companyId  = (string) Str::ulid();
        $propertyId = (string) Str::ulid();

        $issueLog = $this->createDummyIssueLog($companyId, $propertyId);

        $distribution = BEODistribution::forceCreate([
            'id'               => (string) Str::ulid(),
            'company_id'       => $companyId,
            'property_id'      => $propertyId,
            'beo_issue_log_id' => $issueLog->id,
            'status'           => DistributionStatusEnum::DISTRIBUTED,
            'severity'         => DistributionSeverityEnum::MINOR,
        ]);

        $pendingAck = BEOAcknowledgement::forceCreate([
            'id'                   => (string) Str::ulid(),
            'beo_distribution_id'  => $distribution->id,
            'department_id'        => (string) Str::ulid(),
            'status'               => AcknowledgementStatusEnum::PENDING,
            'sla_hours_configured' => 24,
            'sla_breach_at'        => now()->addHours(24),
        ]);
        $viewedAck = BEOAcknowledgement::forceCreate([
            'id'                   => (string) Str::ulid(),
            'beo_distribution_id'  => $distribution->id,
            'department_id'        => (string) Str::ulid(),
            'status'               => AcknowledgementStatusEnum::VIEWED,
            'sla_hours_configured' => 24,
            'sla_breach_at'        => now()->addHours(24),
        ]);
        $escalatedAck = BEOAcknowledgement::forceCreate([
            'id'                   => (string) Str::ulid(),
            'beo_distribution_id'  => $distribution->id,
            'department_id'        => (string) Str::ulid(),
            'status'               => AcknowledgementStatusEnum::ESCALATED,
            'sla_hours_configured' => 24,
            'sla_breach_at'        => now()->subHour(),
        ]);
        $acknowledgedAck = BEOAcknowledgement::forceCreate([
            'id'                   => (string) Str::ulid(),
            'beo_distribution_id'  => $distribution->id,
            'department_id'        => (string) Str::ulid(),
            'status'               => AcknowledgementStatusEnum::ACKNOWLEDGED,
            'sla_hours_configured' => 24,
            'sla_breach_at'        => now()->addHours(24),
        ]);

        // Revision issue log pointing back
        $revisionIssueLog = BEOIssueLog::forceCreate([
            'id'               => (string) Str::ulid(),
            'company_id'       => $companyId,
            'property_id'      => $propertyId,
            'function_id'      => $issueLog->function_id,
            'issue_number'     => 'BEO-002',
            'previous_issue_id' => $issueLog->id,
            'revision_number'  => 1,
            'status'           => 'PUBLISHED',
            'snapshot_payload' => json_encode(['foo' => 'bar']),
            'snapshot_hash'    => 'hash456',
        ]);

        $this->makeService()->createDistribution($revisionIssueLog->id, DistributionSeverityEnum::MAJOR);

        $distribution->refresh();
        $this->assertEquals(DistributionStatusEnum::SUPERSEDED, $distribution->status);

        $pendingAck->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::SUPERSEDED_NO_ACTION, $pendingAck->status);

        $viewedAck->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::SUPERSEDED_NO_ACTION, $viewedAck->status);

        $escalatedAck->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::SUPERSEDED_ESCALATION_CLOSED, $escalatedAck->status);

        $acknowledgedAck->refresh();
        $this->assertEquals(AcknowledgementStatusEnum::ACKNOWLEDGED, $acknowledgedAck->status);
    }

    // -----------------------------------------------------------------------
    // Guard: audit trail written on distribute (sync queue)
    // -----------------------------------------------------------------------

    public function test_audit_trail_written_on_distribute(): void
    {
        $companyId  = (string) Str::ulid();
        $propertyId = (string) Str::ulid();
        $issueLog   = $this->createDummyIssueLog($companyId, $propertyId);
        $actorId    = (string) Str::ulid();

        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);
        $service->distributeBEO($distribution->id, $actorId, [(string) Str::ulid()]);

        $this->assertDatabaseHas('beo_distribution_audit_trails', [
            'distribution_id' => $distribution->id,
            'event_type'      => 'DISTRIBUTED',
            'performed_by'    => $actorId,
        ]);
    }

    // -----------------------------------------------------------------------
    // Guard: cancel flow
    // -----------------------------------------------------------------------

    public function test_cancel_distribution_from_draft(): void
    {
        $issueLog     = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());
        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);

        $cancelled = $service->cancelDistribution($distribution->id, (string) Str::ulid());
        $this->assertEquals(DistributionStatusEnum::CANCELLED, $cancelled->status);
    }

    public function test_cancel_from_terminal_state_throws(): void
    {
        $this->expectException(DistributionStateException::class);

        $issueLog     = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());
        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);
        $distribution->update(['status' => DistributionStatusEnum::CANCELLED]);

        $service->cancelDistribution($distribution->id);
    }

    // -----------------------------------------------------------------------
    // Guard: acknowledge rejects already-acknowledged
    // -----------------------------------------------------------------------

    public function test_acknowledge_rejects_already_acknowledged(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        $issueLog     = $this->createDummyIssueLog((string) Str::ulid(), (string) Str::ulid());
        $service      = $this->makeService();
        $distribution = $service->createDistribution($issueLog->id, DistributionSeverityEnum::MINOR);
        $service->distributeBEO($distribution->id, (string) Str::ulid(), [(string) Str::ulid()]);

        $ack    = $distribution->acknowledgements->first();
        $engine = new AcknowledgementEngine();
        $engine->acknowledge($ack->id, (string) Str::ulid());
        // Second call on the same ack must throw
        $engine->acknowledge($ack->id, (string) Str::ulid());
    }
}
