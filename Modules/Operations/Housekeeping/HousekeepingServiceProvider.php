<?php

namespace Modules\Operations\Housekeeping;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\Housekeeping\Events\CleaningTaskAssigned;
use Modules\Operations\Housekeeping\Events\CleaningTaskCancelled;
use Modules\Operations\Housekeeping\Events\CleaningTaskCompleted;
use Modules\Operations\Housekeeping\Events\CleaningTaskCreated;
use Modules\Operations\Housekeeping\Events\CleaningTaskStarted;
use Modules\Operations\Housekeeping\Events\InspectionCompleted;
use Modules\Operations\Housekeeping\Events\RoomCreated;
use Modules\Operations\Housekeeping\Events\RoomStatusChanged;
use Modules\Operations\Housekeeping\Listeners\LogHousekeepingActivity;
use Modules\Operations\Housekeeping\Listeners\RecordRoomHistory;
use Modules\Operations\Housekeeping\Listeners\RecordTaskHistory;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Observers\CleaningTaskObserver;
use Modules\Operations\Housekeeping\Observers\RoomObserver;
use Modules\Operations\Housekeeping\Policies\ChecklistPolicy;
use Modules\Operations\Housekeeping\Policies\CleaningTaskPolicy;
use Modules\Operations\Housekeeping\Policies\RoomInspectionPolicy;
use Modules\Operations\Housekeeping\Policies\RoomPolicy;
use Modules\Operations\Housekeeping\Policies\TaskAssignmentPolicy;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Models\RoomInspection;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Console\Commands\ConsumeCheckoutTurnoverHandoffsCommand;

class HousekeepingServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');
        $this->commands([
            ConsumeCheckoutTurnoverHandoffsCommand::class,
        ]);

        // Observers
        Room::observe(RoomObserver::class);
        CleaningTask::observe(CleaningTaskObserver::class);

        // Room events
        Event::listen(RoomCreated::class,       [RecordRoomHistory::class,       'handle']);
        Event::listen(RoomCreated::class,       [LogHousekeepingActivity::class, 'handle']);
        Event::listen(RoomStatusChanged::class, [RecordRoomHistory::class,       'handle']);
        Event::listen(RoomStatusChanged::class, [LogHousekeepingActivity::class, 'handle']);

        // Task events
        Event::listen(CleaningTaskCreated::class,   [RecordTaskHistory::class,       'handle']);
        Event::listen(CleaningTaskCreated::class,   [LogHousekeepingActivity::class, 'handle']);
        Event::listen(CleaningTaskAssigned::class,  [RecordTaskHistory::class,       'handle']);
        Event::listen(CleaningTaskAssigned::class,  [LogHousekeepingActivity::class, 'handle']);
        Event::listen(CleaningTaskStarted::class,   [RecordTaskHistory::class,       'handle']);
        Event::listen(CleaningTaskStarted::class,   [LogHousekeepingActivity::class, 'handle']);
        Event::listen(CleaningTaskCompleted::class, [RecordTaskHistory::class,       'handle']);
        Event::listen(CleaningTaskCompleted::class, [LogHousekeepingActivity::class, 'handle']);
        Event::listen(CleaningTaskCancelled::class, [RecordTaskHistory::class,       'handle']);
        Event::listen(CleaningTaskCancelled::class, [LogHousekeepingActivity::class, 'handle']);

        // Inspection events
        Event::listen(InspectionCompleted::class, [LogHousekeepingActivity::class, 'handle']);

        // Policies
        Gate::policy(Room::class,             RoomPolicy::class);
        Gate::policy(CleaningTask::class,     CleaningTaskPolicy::class);
        Gate::policy(TaskAssignment::class,   TaskAssignmentPolicy::class);
        Gate::policy(RoomInspection::class,   RoomInspectionPolicy::class);
        Gate::policy(CleaningChecklist::class, ChecklistPolicy::class);

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }
}
