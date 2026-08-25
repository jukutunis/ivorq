<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Collection;
use InvalidArgumentException;
use Modules\Finance\CostControl\Enums\CostDeliveryProcessingState;
use Modules\Finance\CostControl\Models\CostDeliveryOutboxDisposition;

final class CostDeliveryObservabilityProjection
{
    public function forProperty(
        string $propertyId,
        ?string $itemId = null,
        ?CostDeliveryProcessingState $processingState = null,
    ): Collection {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('Cost delivery observability requires a Property identifier.');
        }

        return CostDeliveryOutboxDisposition::query()
            ->where('property_id', $propertyId)
            ->when($itemId !== null, fn ($query) => $query->where('item_id', $itemId))
            ->when(
                $processingState !== null,
                fn ($query) => $query->where('processing_state', $processingState->value),
            )
            ->orderBy('valuation_scope')
            ->orderBy('valuation_sequence')
            ->get([
                'id',
                'property_id',
                'item_id',
                'processing_state',
                'valuation_scope',
                'valuation_sequence',
                'classification',
                'last_failure_code',
                'is_recoverable',
                'expected_sequence',
                'attempt_count',
                'last_attempted_at',
                'historical_excluded_at',
                'delivered_at',
            ])
            ->map(fn (CostDeliveryOutboxDisposition $row): array => [
                'id' => $row->id,
                'property_id' => $row->property_id,
                'item_id' => $row->item_id,
                'processing_state' => $row->processing_state->value,
                'valuation_scope' => $row->valuation_scope,
                'valuation_sequence' => $row->valuation_sequence,
                'classification' => $row->classification->value,
                'last_failure_code' => $row->last_failure_code,
                'is_recoverable' => $row->is_recoverable,
                'expected_sequence' => $row->expected_sequence,
                'attempt_count' => $row->attempt_count,
                'last_attempted_at' => $row->last_attempted_at?->toIso8601String(),
                'historical_excluded_at' => $row->historical_excluded_at?->toIso8601String(),
                'delivered_at' => $row->delivered_at?->toIso8601String(),
            ]);
    }

    public function countsByProcessingState(string $propertyId): array
    {
        if (trim($propertyId) === '') {
            throw new InvalidArgumentException('Cost delivery observability requires a Property identifier.');
        }

        $counts = CostDeliveryOutboxDisposition::query()
            ->where('property_id', $propertyId)
            ->selectRaw('processing_state, COUNT(*) AS aggregate')
            ->groupBy('processing_state')
            ->pluck('aggregate', 'processing_state');

        return collect(CostDeliveryProcessingState::cases())
            ->mapWithKeys(fn (CostDeliveryProcessingState $state): array => [
                $state->value => (int) ($counts[$state->value] ?? 0),
            ])
            ->all();
    }
}
