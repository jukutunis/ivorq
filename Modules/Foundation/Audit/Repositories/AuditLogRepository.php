<?php

namespace Modules\Foundation\Audit\Repositories;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Modules\Foundation\Audit\Models\AuditLog;
use Shared\Exceptions\NotFoundException;

class AuditLogRepository
{
    private const SECURITY_OPERATION_EVENTS = [
        'sensitive_action_confirmed',
        'sensitive_action_invalidated',
        'fx_operational_role_assign',
        'fx_operational_role_revoke',
        'fx_break_glass_activated',
        'fx_break_glass_deactivated',
    ];

    public function securityOperationsForProperty(string $propertyId): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('property_id', $propertyId)
            ->whereIn('event', self::SECURITY_OPERATION_EVENTS)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate(20, ['id', 'event', 'user_id', 'auditable_id', 'created_at'], 'audit_page')
            ->withQueryString()
            ->through(fn (AuditLog $log): array => [
                'id' => $log->id,
                'event' => $log->event,
                'actor_user_id' => $log->user_id,
                'subject_id' => $log->auditable_id,
                'occurred_at' => $log->created_at?->toIso8601String(),
            ]);
    }

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
