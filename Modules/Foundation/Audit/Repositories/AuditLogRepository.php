<?php

namespace Modules\Foundation\Audit\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Audit\Models\AuditLog;
use Shared\Exceptions\NotFoundException;

class AuditLogRepository
{
    public function paginate(array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        $propertyId = app(\Shared\Services\CurrentPropertyService::class)->getId();

        return AuditLog::query()
            ->when($propertyId, fn($q) => $q->where('property_id', $propertyId))
            ->when($filters['user_id'] ?? null, fn($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn($q, $v) => $q->where('event', $v))
            ->when($filters['auditable_type'] ?? null, fn($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['auditable_id'] ?? null, fn($q, $v) => $q->where('auditable_id', $v))
            ->when($filters['from'] ?? null, fn($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function find(string $id): AuditLog
    {
        $log = AuditLog::find($id);

        throw_if(! $log, new NotFoundException('Audit Log'));

        return $log;
    }

    public function forModel(string $type, string $id): LengthAwarePaginator
    {
        return AuditLog::where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderByDesc('created_at')
            ->paginate(20);
    }
}
