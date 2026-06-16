<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOIssueLog;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use InvalidArgumentException;

class BEODistributionService
{
    public function createDistribution(string $beoIssueLogId, DistributionSeverityEnum $severity): BEODistribution
    {
        $issueLog = BEOIssueLog::findOrFail($beoIssueLogId);

        // Handle supersede flow
        $this->supersedePreviousDistribution($issueLog);

        $distribution = BEODistribution::create([
            'company_id' => $issueLog->company_id,
            'property_id' => $issueLog->property_id,
            'beo_issue_log_id' => $issueLog->id,
            'status' => DistributionStatusEnum::DRAFT,
            'severity' => $severity,
        ]);

        return $distribution;
    }

    public function distributeBEO(string $distributionId, string $distributedBy, array $departmentIds): BEODistribution
    {
        $distribution = BEODistribution::findOrFail($distributionId);

        if ($distribution->status !== DistributionStatusEnum::DRAFT) {
            throw new InvalidArgumentException("Only DRAFT distributions can be distributed.");
        }

        $distribution->update([
            'status' => DistributionStatusEnum::DISTRIBUTED,
            'distributed_at' => now(),
            'distributed_by' => $distributedBy,
        ]);

        // Generate Acknowledgements
        foreach ($departmentIds as $departmentId) {
            // SLA rules can be fetched from a config or department setting, default to 24h
            $slaHours = 24; 
            BEOAcknowledgement::create([
                'beo_distribution_id' => $distribution->id,
                'department_id' => $departmentId,
                'status' => AcknowledgementStatusEnum::PENDING,
                'sla_hours_configured' => $slaHours,
                'sla_breach_at' => now()->addHours($slaHours),
            ]);
        }

        return $distribution;
    }

    protected function supersedePreviousDistribution(BEOIssueLog $newIssueLog): void
    {
        if (!$newIssueLog->previous_issue_id) {
            return;
        }

        $previousDistributions = BEODistribution::where('beo_issue_log_id', $newIssueLog->previous_issue_id)
            ->whereIn('status', [
                DistributionStatusEnum::PUBLISHED,
                DistributionStatusEnum::DISTRIBUTED,
                DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED,
                DistributionStatusEnum::FULLY_ACKNOWLEDGED,
                DistributionStatusEnum::ESCALATED
            ])->get();

        foreach ($previousDistributions as $dist) {
            $dist->update(['status' => DistributionStatusEnum::SUPERSEDED]);
        }
    }
}
