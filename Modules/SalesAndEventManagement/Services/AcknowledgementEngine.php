<?php

namespace Modules\SalesAndEventManagement\Services;

use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Enums\AcknowledgementStatusEnum;
use Modules\SalesAndEventManagement\Enums\DistributionStatusEnum;
use Illuminate\Support\Facades\DB;

class AcknowledgementEngine
{
    public function markAsViewed(string $acknowledgementId): BEOAcknowledgement
    {
        $ack = BEOAcknowledgement::findOrFail($acknowledgementId);

        if ($ack->status === AcknowledgementStatusEnum::PENDING) {
            $ack->update([
                'status' => AcknowledgementStatusEnum::VIEWED,
                'viewed_at' => now(),
            ]);
        }

        return $ack;
    }

    public function acknowledge(string $acknowledgementId, string $userId): BEOAcknowledgement
    {
        return DB::transaction(function () use ($acknowledgementId, $userId) {
            $ack = BEOAcknowledgement::findOrFail($acknowledgementId);
            
            $ack->update([
                'status' => AcknowledgementStatusEnum::ACKNOWLEDGED,
                'user_id' => $userId,
                'acknowledged_at' => now(),
            ]);

            $this->recalculateDistributionStatus($ack->beo_distribution_id);

            return $ack;
        });
    }

    public function reject(string $acknowledgementId, string $userId, string $reason): BEOAcknowledgement
    {
        $ack = BEOAcknowledgement::findOrFail($acknowledgementId);

        $ack->update([
            'status' => AcknowledgementStatusEnum::REJECTED,
            'user_id' => $userId,
            'rejection_reason' => $reason,
        ]);

        return $ack;
    }

    protected function recalculateDistributionStatus(string $distributionId): void
    {
        $distribution = BEODistribution::findOrFail($distributionId);
        $acks = $distribution->acknowledgements;

        $total = $acks->count();
        $acknowledged = $acks->where('status', AcknowledgementStatusEnum::ACKNOWLEDGED)->count();

        if ($total > 0 && $acknowledged === $total) {
            $distribution->update(['status' => DistributionStatusEnum::FULLY_ACKNOWLEDGED]);
        } elseif ($acknowledged > 0) {
            $distribution->update(['status' => DistributionStatusEnum::PARTIALLY_ACKNOWLEDGED]);
        }
    }
}
