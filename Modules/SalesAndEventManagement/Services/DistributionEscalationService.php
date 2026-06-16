<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Models\DistributionEscalation;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Illuminate\Support\Collection;

class DistributionEscalationService
{
    public function detectAndEscalateBreaches(): Collection
    {
        // Find acknowledgements that breached SLA and are not yet acknowledged or rejected
        $breachedAcks = BEOAcknowledgement::where('sla_breach_at', '<', now())
            ->whereIn('status', [
                AcknowledgementStatusEnum::PENDING,
                AcknowledgementStatusEnum::VIEWED
            ])
            ->get();

        $escalations = collect();

        foreach ($breachedAcks as $ack) {
            // Mark as escalated
            $ack->update(['status' => AcknowledgementStatusEnum::ESCALATED]);

            // Create Escalation history
            // Normally escalated_to_role_id would be determined via department hierarchy or matrix.
            // For now, we mock the role_id or leave it null.
            $escalation = DistributionEscalation::create([
                'beo_acknowledgement_id' => $ack->id,
                'escalation_level' => 1,
                'escalated_at' => now(),
            ]);

            $escalations->push($escalation);

            // Dispatch notification contracts (stubbed for future integration)
            $this->dispatchEscalationNotification($escalation);
        }

        return $escalations;
    }

    protected function dispatchEscalationNotification(DistributionEscalation $escalation): void
    {
        // Notification readiness stub
        // e.g., Event::dispatch(new AcknowledgementEscalated($escalation));
    }
}
