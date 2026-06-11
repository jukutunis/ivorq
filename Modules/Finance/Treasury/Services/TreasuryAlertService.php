<?php

namespace Modules\Finance\Treasury\Services;

use Modules\Finance\Treasury\Models\TreasuryAlertLog;
use Modules\Finance\Treasury\Enums\TreasuryAlertSeverityEnum;

class TreasuryAlertService
{
    public function evaluateAlerts(string $propertyId, array $metrics): array
    {
        $alerts = [];

        if ($metrics['Current Cash Position'] > 0 && $metrics['Liquidity Coverage Ratio'] < 1.0) {
            $alerts[] = $this->logAlert(
                $propertyId,
                'Low Cash Alert',
                TreasuryAlertSeverityEnum::Warning,
                'Current cash is insufficient to cover projected 30-day burn.'
            );
        }

        if ($metrics['Current Cash Position'] < 0 || $metrics['Projected Cash 30 Days'] < 0) {
            $alerts[] = $this->logAlert(
                $propertyId,
                'Negative Cash Alert',
                TreasuryAlertSeverityEnum::Critical,
                'Cash position is or will be negative within 30 days.'
            );
        }

        if ($metrics['Days Since Last Reconciliation'] > 30) {
            $alerts[] = $this->logAlert(
                $propertyId,
                'Reconciliation Stale Alert',
                TreasuryAlertSeverityEnum::High,
                'Bank reconciliation is older than 30 days.'
            );
        }

        return $alerts;
    }

    protected function logAlert(string $propertyId, string $type, TreasuryAlertSeverityEnum $severity, string $message): array
    {
        $alertData = [
            'alert_type' => $type,
            'severity' => $severity->value,
            'message' => $message,
        ];

        if (in_array($severity, [TreasuryAlertSeverityEnum::High, TreasuryAlertSeverityEnum::Critical])) {
            TreasuryAlertLog::create([
                'property_id' => $propertyId,
                'alert_type' => $type,
                'severity' => $severity,
                'message' => $message,
                'logged_at' => now(),
            ]);
        }

        return $alertData;
    }
}
