<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

class WorkspacePriorityEngine
{
    /**
     * Priority Formula:
     * Guest Impact       = 35%
     * Incident Severity  = 25%
     * SLA Breach Risk    = 20%
     * Asset Criticality  = 15%
     * WO Priority        = 5%
     */
    public function calculateScore(array $factors): float
    {
        $score = 0;
        $score += ($factors['guest_impact'] ?? 0) * 0.35;
        $score += ($factors['incident_severity'] ?? 0) * 0.25;
        $score += ($factors['sla_risk'] ?? 0) * 0.20;
        $score += ($factors['asset_criticality'] ?? 0) * 0.15;
        $score += ($factors['wo_priority'] ?? 0) * 0.05;

        return round($score, 2);
    }
}
