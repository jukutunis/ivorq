<?php

namespace Modules\Operations\Zoning\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Modules\Operations\Zoning\Models\ZoneHistory;

/**
 * Read-only repository for zone_histories.
 *
 * Writes go exclusively through ZoneHistory::record() called from listeners.
 * No create(), update(), or delete() methods exist here by design.
 */
class ZoneHistoryRepository
{
    public function forZone(string $zoneId): Collection
    {
        return ZoneHistory::where('zone_id', $zoneId)
            ->with('performer')
            ->latest('created_at')
            ->get();
    }

    public function forZonePaginated(string $zoneId, int $perPage = 20): LengthAwarePaginator
    {
        return ZoneHistory::where('zone_id', $zoneId)
            ->with('performer')
            ->latest('created_at')
            ->paginate($perPage);
    }

    public function forProperty(string $propertyId, int $perPage = 20): LengthAwarePaginator
    {
        return ZoneHistory::where('property_id', $propertyId)
            ->with(['zone', 'performer'])
            ->latest('created_at')
            ->paginate($perPage);
    }
}
