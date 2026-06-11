<?php

namespace Modules\Operations\Maintenance\Listeners;

use Modules\Operations\Maintenance\Events\MaintenanceExecutionCompleted;
use Modules\Operations\Maintenance\Services\MaintenanceHistoryService;

class LogMaintenanceHistory
{
    public function __construct(protected MaintenanceHistoryService $historyService) {}

    public function handle(MaintenanceExecutionCompleted $event): void
    {
        $this->historyService->recordHistory($event->execution);
    }
}
