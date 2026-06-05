<?php

namespace Modules\Operations\Engineering;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\Engineering\Events\AssetRequestApproved;
use Modules\Operations\Engineering\Events\AssetRequestFulfilled;
use Modules\Operations\Engineering\Events\AssetRequestRejected;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskCompleted;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskGenerated;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskOverdue;
use Modules\Operations\Engineering\Events\WorkOrderAssigned;
use Modules\Operations\Engineering\Events\WorkOrderCancelled;
use Modules\Operations\Engineering\Events\WorkOrderCompleted;
use Modules\Operations\Engineering\Events\WorkOrderCreated;
use Modules\Operations\Engineering\Events\WorkOrderOnHold;
use Modules\Operations\Engineering\Events\WorkOrderStarted;
use Modules\Operations\Engineering\Listeners\LogEngineeringActivity;
use Modules\Operations\Engineering\Listeners\RecordWorkOrderHistory;
use Modules\Operations\Engineering\Listeners\UpdatePreventiveMaintenanceSchedule;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Models\TechnicianAssignment;
use Modules\Operations\Engineering\Models\WorkOrder;
use Modules\Operations\Engineering\Policies\AssetRequestPolicy;
use Modules\Operations\Engineering\Policies\EngineeringChecklistPolicy;
use Modules\Operations\Engineering\Policies\PreventiveMaintenancePolicy;
use Modules\Operations\Engineering\Policies\PreventiveMaintenanceTaskPolicy;
use Modules\Operations\Engineering\Policies\TechnicianAssignmentPolicy;
use Modules\Operations\Engineering\Policies\WorkOrderPolicy;

class EngineeringServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // ── Policies ─────────────────────────────────────────────────────────
        Gate::policy(WorkOrder::class,               WorkOrderPolicy::class);
        Gate::policy(TechnicianAssignment::class,    TechnicianAssignmentPolicy::class);
        Gate::policy(PreventiveMaintenance::class,   PreventiveMaintenancePolicy::class);
        Gate::policy(PreventiveMaintenanceTask::class, PreventiveMaintenanceTaskPolicy::class);
        Gate::policy(AssetRequest::class,            AssetRequestPolicy::class);
        Gate::policy(EngineeringChecklist::class,    EngineeringChecklistPolicy::class);

        // ── Work Order events ─────────────────────────────────────────────────
        Event::listen(WorkOrderCreated::class,   [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderCreated::class,   [LogEngineeringActivity::class,  'handle']);

        Event::listen(WorkOrderAssigned::class,  [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderAssigned::class,  [LogEngineeringActivity::class,  'handle']);

        Event::listen(WorkOrderStarted::class,   [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderStarted::class,   [LogEngineeringActivity::class,  'handle']);

        Event::listen(WorkOrderOnHold::class,    [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderOnHold::class,    [LogEngineeringActivity::class,  'handle']);

        Event::listen(WorkOrderCompleted::class, [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderCompleted::class, [LogEngineeringActivity::class,  'handle']);

        Event::listen(WorkOrderCancelled::class, [RecordWorkOrderHistory::class,  'handle']);
        Event::listen(WorkOrderCancelled::class, [LogEngineeringActivity::class,  'handle']);

        // ── Preventive Maintenance Task events ────────────────────────────────
        Event::listen(PreventiveMaintenanceTaskGenerated::class, [LogEngineeringActivity::class,               'handle']);

        Event::listen(PreventiveMaintenanceTaskCompleted::class, [UpdatePreventiveMaintenanceSchedule::class,  'handle']);
        Event::listen(PreventiveMaintenanceTaskCompleted::class, [LogEngineeringActivity::class,               'handle']);

        Event::listen(PreventiveMaintenanceTaskOverdue::class,   [LogEngineeringActivity::class,               'handle']);

        // ── Asset Request events ──────────────────────────────────────────────
        Event::listen(AssetRequestApproved::class,  [LogEngineeringActivity::class, 'handle']);
        Event::listen(AssetRequestRejected::class,  [LogEngineeringActivity::class, 'handle']);
        Event::listen(AssetRequestFulfilled::class, [LogEngineeringActivity::class, 'handle']);

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }
}
