<?php

namespace Modules\SalesAndEventManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\SalesAndEventManagement\Events\DistributionCancelledEvent;
use Modules\SalesAndEventManagement\Events\DistributionDistributedEvent;
use Modules\SalesAndEventManagement\Events\DistributionSupersededEvent;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Exceptions\DistributionStateException;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;

class BEODistributionService
{
    public function __construct(
        private readonly DistributionStateMachine $stateMachine,
    ) {}

    /**
     * Create a DRAFT distribution for a BEO issue log.
     * Supersedes any active prior distribution if a revision chain exists.
     */
    public function createDistribution(
        string $beoIssueLogId,
        DistributionSeverityEnum $severity,
    ): BEODistribution {
        $issueLog = BEOIssueLog::findOrFail($beoIssueLogId);

        return DB::transaction(function () use ($issueLog, $severity) {
            // Supersede previous distributions before creating the new one
            $this->supersedePreviousDistribution($issueLog);

            return BEODistribution::create([
                'company_id'       => $issueLog->company_id,
                'property_id'      => $issueLog->property_id,
                'beo_issue_log_id' => $issueLog->id,
                'status'           => DistributionStatusEnum::DRAFT,
                'severity'         => $severity,
            ]);
        });
    }

    /**
     * Distribute a DRAFT BEO to the given departments.
     * Guarded by DistributionStateMachine.
     */
    public function distributeBEO(
        string $distributionId,
        string $distributedBy,
        array $departmentIds,
    ): BEODistribution {
        if (empty($departmentIds)) {
            throw new \InvalidArgumentException('At least one department must be specified for distribution.');
        }

        return DB::transaction(function () use ($distributionId, $distributedBy, $departmentIds) {
            $distribution = BEODistribution::findOrFail($distributionId);

            $this->stateMachine->guard($distribution->status, DistributionStatusEnum::DISTRIBUTED);

            $distribution->update([
                'status'         => DistributionStatusEnum::DISTRIBUTED,
                'distributed_at' => now(),
                'distributed_by' => $distributedBy,
            ]);

            foreach ($departmentIds as $departmentId) {
                // SLA default: 24h. Dynamic per-department SLA configuration is a future-phase
                // concern (Sprint 14.8.5.1 §9); the hardcoded default is the approved interim.
                $slaHours = 24;
                BEOAcknowledgement::create([
                    'beo_distribution_id'  => $distribution->id,
                    'department_id'        => $departmentId,
                    'status'               => AcknowledgementStatusEnum::PENDING,
                    'sla_hours_configured' => $slaHours,
                    'sla_breach_at'        => now()->addHours($slaHours),
                ]);
            }

            DistributionDistributedEvent::dispatch($distribution, $distributedBy, $departmentIds);

            return $distribution;
        });
    }

    /**
     * Cancel a distribution from any non-terminal state.
     * Guarded by DistributionStateMachine.
     */
    public function cancelDistribution(string $distributionId, ?string $performedBy = null): BEODistribution
    {
        return DB::transaction(function () use ($distributionId, $performedBy) {
            $distribution = BEODistribution::findOrFail($distributionId);
            $oldStatus    = $distribution->status->value;

            $this->stateMachine->guard($distribution->status, DistributionStatusEnum::CANCELLED);

            $distribution->update(['status' => DistributionStatusEnum::CANCELLED]);

            DistributionCancelledEvent::dispatch($distribution, $oldStatus, $performedBy);

            return $distribution;
        });
    }

    /**
     * Supersede active distributions from the previous issue log in the revision chain.
     * Applies the approved cascade policy to orphaned acknowledgements (Sprint 14.8.5.1 §4).
     */
    protected function supersedePreviousDistribution(BEOIssueLog $newIssueLog): void
    {
        if (! $newIssueLog->previous_issue_id) {
            return;
        }

        $supersedableStatuses = [
            DistributionStatusEnum::DISTRIBUTED,
            DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED,
            DistributionStatusEnum::FULLY_ACKNOWLEDGED,
            DistributionStatusEnum::ESCALATED,
        ];

        $previousDistributions = BEODistribution::where('beo_issue_log_id', $newIssueLog->previous_issue_id)
            ->whereIn('status', $supersedableStatuses)
            ->get();

        foreach ($previousDistributions as $dist) {
            $oldStatus = $dist->status->value;

            $dist->update(['status' => DistributionStatusEnum::SUPERSEDED]);

            // Cascade policy: close orphaned acknowledgements (Sprint 14.8.5.1 §4)
            $this->cascadeSupersededAcknowledgements($dist);

            DistributionSupersededEvent::dispatch($dist, $oldStatus);
        }
    }

    /**
     * Apply the approved supersede cascade matrix to child acknowledgements.
     *
     * PENDING  → SUPERSEDED_NO_ACTION
     * VIEWED   → SUPERSEDED_NO_ACTION
     * ESCALATED → SUPERSEDED_ESCALATION_CLOSED
     * ACKNOWLEDGED, REJECTED → unchanged (historical record)
     */
    protected function cascadeSupersededAcknowledgements(BEODistribution $distribution): void
    {
        $acks = $distribution->acknowledgements;

        foreach ($acks as $ack) {
            match ($ack->status) {
                AcknowledgementStatusEnum::PENDING,
                AcknowledgementStatusEnum::VIEWED => $ack->update([
                    'status' => AcknowledgementStatusEnum::SUPERSEDED_NO_ACTION,
                ]),
                AcknowledgementStatusEnum::ESCALATED => $ack->update([
                    'status' => AcknowledgementStatusEnum::SUPERSEDED_ESCALATION_CLOSED,
                ]),
                // ACKNOWLEDGED and REJECTED are maintained historically — no update
                default => null,
            };
        }
    }
}
