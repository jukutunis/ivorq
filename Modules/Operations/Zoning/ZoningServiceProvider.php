<?php

namespace Modules\Operations\Zoning;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Modules\Operations\Zoning\Events\ZoneAssigned;
use Modules\Operations\Zoning\Events\ZoneAssignmentEnded;
use Modules\Operations\Zoning\Events\ZoneCreated;
use Modules\Operations\Zoning\Events\ZoneReassigned;
use Modules\Operations\Zoning\Events\ZoneStatusChanged;
use Modules\Operations\Zoning\Listeners\LogZoneActivity;
use Modules\Operations\Zoning\Listeners\RecordZoneHistory;
use Modules\Operations\Zoning\Models\Zone;
use Modules\Operations\Zoning\Models\ZoneAssignment;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Modules\Operations\Zoning\Observers\ZoneAssignmentObserver;
use Modules\Operations\Zoning\Observers\ZoneObserver;
use Modules\Operations\Zoning\Policies\ZoneAssignmentPolicy;
use Modules\Operations\Zoning\Policies\ZonePolicy;
use Modules\Operations\Zoning\Policies\ZoneTemplatePolicy;

class ZoningServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/database/migrations');

        // Observers
        Zone::observe(ZoneObserver::class);
        ZoneAssignment::observe(ZoneAssignmentObserver::class);

        // Events → Listeners
        Event::listen(ZoneCreated::class,         [RecordZoneHistory::class, 'handle']);
        Event::listen(ZoneCreated::class,         [LogZoneActivity::class,   'handle']);
        Event::listen(ZoneStatusChanged::class,   [RecordZoneHistory::class, 'handle']);
        Event::listen(ZoneStatusChanged::class,   [LogZoneActivity::class,   'handle']);
        Event::listen(ZoneAssigned::class,        [RecordZoneHistory::class, 'handle']);
        Event::listen(ZoneAssigned::class,        [LogZoneActivity::class,   'handle']);
        Event::listen(ZoneReassigned::class,      [RecordZoneHistory::class, 'handle']);
        Event::listen(ZoneReassigned::class,      [LogZoneActivity::class,   'handle']);
        Event::listen(ZoneAssignmentEnded::class, [RecordZoneHistory::class, 'handle']);
        Event::listen(ZoneAssignmentEnded::class, [LogZoneActivity::class,   'handle']);

        // Policies
        Gate::policy(Zone::class,           ZonePolicy::class);
        Gate::policy(ZoneAssignment::class, ZoneAssignmentPolicy::class);
        Gate::policy(ZoneTemplate::class,   ZoneTemplatePolicy::class);

        $this->loadRoutesFrom(__DIR__ . '/routes/web.php');
    }
}
