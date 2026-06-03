<?php

namespace Modules\Foundation\Activity\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Activity\Models\ActivityLog;
use Shared\Exceptions\NotFoundException;

class ActivityLogRepository
{
    public function paginate(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();

        return ActivityLog::query()
            ->when($propertyId, fn($q) => $q->where('property_id', $propertyId))
            ->when($filters['user_id'] ?? null, fn($q, $v) => $q->where('user_id', $v))
            ->when($filters['subject_type'] ?? null, fn($q, $v) => $q->where('subject_type', $v))
            ->when($filters['subject_id'] ?? null, fn($q, $v) => $q->where('subject_id', $v))
            ->when($filters['from'] ?? null, fn($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function find(string $id): ActivityLog
    {
        $log = ActivityLog::find($id);

        throw_if(! $log, new NotFoundException('Activity Log'));

        return $log;
    }

    public function forSubject(string $type, string $id): LengthAwarePaginator
    {
        return ActivityLog::where('subject_type', $type)
            ->where('subject_id', $id)
            ->orderByDesc('created_at')
            ->paginate(20);
    }
}
