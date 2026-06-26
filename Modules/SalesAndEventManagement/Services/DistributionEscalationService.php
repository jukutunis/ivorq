<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Events\DistributionEscalatedEvent;
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
            $ack->update(['status' => AcknowledgementStatusEnum::ESCALATED]);

            $escalation = DistributionEscalation::create([
                'beo_acknowledgement_id' => $ack->id,
                'escalation_level'       => 1,
                'escalated_at'           => now(),
            ]);

            $escalations->push($escalation);

            // Dispatch domain event; notification engine subscribes externally (Sprint 14.8.5.1 §8)
            DistributionEscalatedEvent::dispatch($escalation);
        }

        return $escalations;
    }
}
