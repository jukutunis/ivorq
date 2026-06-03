<?php

namespace Modules\Foundation\Audit\Observers;

use Illuminate\Database\Eloquent\Model;
use Modules\Foundation\Audit\Services\AuditService;

class AuditObserver
{
    public function __construct(
        private AuditService $auditService
    ) {}

    public function created(Model $model): void
    {
        $this->auditService->log(
            event: 'created',
            model: $model,
            newValues: $model->getAttributes()
        );
    }

    public function updated(Model $model): void
    {
        $this->auditService->log(
            event: 'updated',
            model: $model,
            oldValues: $model->getOriginal(),
            newValues: $model->getChanges()
        );
    }

    public function deleted(Model $model): void
    {
        $this->auditService->log(
            event: 'deleted',
            model: $model,
            oldValues: $model->getAttributes()
        );
    }

    public function restored(Model $model): void
    {
        $this->auditService->log(
            event: 'restored',
            model: $model,
            newValues: $model->getAttributes()
        );
    }
}
