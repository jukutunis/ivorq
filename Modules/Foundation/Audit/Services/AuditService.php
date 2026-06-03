<?php

namespace Modules\Foundation\Audit\Services;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Audit\Models\AuditLog;

class AuditService
{
    private array $excludedAttributes = [
        'password',
        'remember_token',
        'updated_at',
    ];

    public function log(
        string $event,
        Model $model,
        array $oldValues = [],
        array $newValues = [],
        array $tags = []
    ): AuditLog {
        return AuditLog::record([
            'property_id'     => $this->resolvePropertyId($model),
            'user_id'         => auth()->id(),
            'event'           => $event,
            'auditable_type'  => get_class($model),
            'auditable_id'    => $model->getKey(),
            'old_values'      => $this->sanitize($oldValues),
            'new_values'      => $this->sanitize($newValues),
            'ip_address'      => request()->ip(),
            'user_agent'      => request()->userAgent(),
            'url'             => request()->fullUrl(),
            'tags'            => $tags,
        ]);
    }

    private function sanitize(array $values): array
    {
        return array_diff_key($values, array_flip($this->excludedAttributes));
    }

    private function resolvePropertyId(Model $model): ?string
    {
        if (isset($model->property_id)) {
            return $model->property_id;
        }

        return app(\Shared\Services\CurrentPropertyService::class)->getId();
    }
}
