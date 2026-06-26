<?php

namespace Modules\SalesAndEventManagement\Services;

use Illuminate\Support\Facades\DB;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgedEvent;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgementRejectedEvent;
use Modules\SalesAndEventManagement\Events\DistributionCompletedEvent;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Models\BEODistribution;

class AcknowledgementEngine
{
    /**
     * Mark an acknowledgement as viewed (PENDING only; idempotent on other states).
     */
    public function markAsViewed(string $acknowledgementId): BEOAcknowledgement
    {
        $ack = BEOAcknowledgement::findOrFail($acknowledgementId);

        if ($ack->status === AcknowledgementStatusEnum::PENDING) {
            $ack->update([
                'status'    => AcknowledgementStatusEnum::VIEWED,
                'viewed_at' => now(),
            ]);
        }

        return $ack;
    }

    /**
     * Acknowledge a BEO on behalf of a department user.
     * Only PENDING or VIEWED acknowledgements may be acknowledged.
     * Recalculates parent distribution status after update.
     */
    public function acknowledge(string $acknowledgementId, string $userId): BEOAcknowledgement
    {
        return DB::transaction(function () use ($acknowledgementId, $userId) {
            $ack = BEOAcknowledgement::findOrFail($acknowledgementId);

            if (! in_array($ack->status, [
                AcknowledgementStatusEnum::PENDING,
                AcknowledgementStatusEnum::VIEWED,
                AcknowledgementStatusEnum::ESCALATED,
            ], true)) {
                throw new \InvalidArgumentException(
                    "Acknowledgement [{$ack->id}] cannot be acknowledged from status [{$ack->status->value}]."
                );
            }

            $ack->update([
                'status'          => AcknowledgementStatusEnum::ACKNOWLEDGED,
                'user_id'         => $userId,
                'acknowledged_at' => now(),
            ]);

            DistributionAcknowledgedEvent::dispatch($ack, $userId);

            $this->recalculateDistributionStatus($ack->beo_distribution_id);

            return $ack;
        });
    }

    /**
     * Reject a BEO acknowledgement with a mandatory reason.
     * Dispatches DistributionAcknowledgementRejectedEvent.
     */
    public function reject(string $acknowledgementId, string $userId, string $reason): BEOAcknowledgement
    {
        return DB::transaction(function () use ($acknowledgementId, $userId, $reason) {
            $ack = BEOAcknowledgement::findOrFail($acknowledgementId);

            $ack->update([
                'status'           => AcknowledgementStatusEnum::REJECTED,
                'user_id'          => $userId,
                'rejection_reason' => $reason,
            ]);

            DistributionAcknowledgementRejectedEvent::dispatch($ack, $userId, $reason);

            return $ack;
        });
    }

    /**
     * Recalculate and persist the parent BEODistribution status based on current acknowledgements.
     * Progression: DISTRIBUTED → PARTIALLY_ACKNOWLEDGED → FULLY_ACKNOWLEDGED → COMPLETED.
     */
    protected function recalculateDistributionStatus(string $distributionId): void
    {
        $distribution = BEODistribution::findOrFail($distributionId);
        $acks         = $distribution->acknowledgements;

        $total        = $acks->count();
        $acknowledged = $acks->where('status', AcknowledgementStatusEnum::ACKNOWLEDGED)->count();
        $active       = $acks->whereNotIn('status', [
            AcknowledgementStatusEnum::SUPERSEDED_NO_ACTION,
            AcknowledgementStatusEnum::SUPERSEDED_ESCALATION_CLOSED,
        ])->count();

        if ($total === 0) {
            return;
        }

        // Do not touch a terminal or superseded parent
        if (in_array($distribution->status, [
            DistributionStatusEnum::SUPERSEDED,
            DistributionStatusEnum::CANCELLED,
            DistributionStatusEnum::COMPLETED,
        ], true)) {
            return;
        }

        if ($active > 0 && $acknowledged === $active) {
            // All active departments acknowledged → FULLY_ACKNOWLEDGED, then COMPLETED
            $distribution->update(['status' => DistributionStatusEnum::FULLY_ACKNOWLEDGED]);
            $distribution->update(['status' => DistributionStatusEnum::COMPLETED]);
            DistributionCompletedEvent::dispatch($distribution);
        } elseif ($acknowledged > 0) {
            $distribution->update(['status' => DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED]);
        }
    }
}
