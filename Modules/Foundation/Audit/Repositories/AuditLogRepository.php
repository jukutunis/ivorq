<?php

namespace Modules\Foundation\Audit\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Audit\Models\AuditLog;
use Shared\Exceptions\NotFoundException;

class AuditLogRepository
{
    public function paginateForProperty(string $propertyId, array $filters = [], int $perPage = 30): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('property_id', $propertyId)
            ->when($filters['user_id'] ?? null, fn ($q, $v) => $q->where('user_id', $v))
            ->when($filters['event'] ?? null, fn ($q, $v) => $q->where('event', $v))
            ->when($filters['auditable_type'] ?? null, fn ($q, $v) => $q->where('auditable_type', $v))
            ->when($filters['auditable_id'] ?? null, fn ($q, $v) => $q->where('auditable_id', $v))
            ->when($filters['from'] ?? null, fn ($q, $v) => $q->where('created_at', '>=', $v))
            ->when($filters['to'] ?? null, fn ($q, $v) => $q->where('created_at', '<=', $v))
            ->orderByDesc('created_at')
            ->paginate($perPage);
    }

    public function findForProperty(string $id, string $propertyId): AuditLog
    {
        $log = AuditLog::query()
            ->where('property_id', $propertyId)
            ->whereKey($id)
            ->first();

        throw_if(! $log, new NotFoundException('Audit Log'));

        return $log;
    }

    public function forModelInProperty(string $type, string $id, string $propertyId): LengthAwarePaginator
    {
        return AuditLog::where('property_id', $propertyId)
            ->where('auditable_type', $type)
            ->where('auditable_id', $id)
            ->orderByDesc('created_at')
            ->paginate(20);
    }
}
