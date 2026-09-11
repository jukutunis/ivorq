<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Listeners\ApprovalNotificationListener;
use Modules\Foundation\Notification\Listeners\TaskEventListener;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgedEvent;
use Modules\SalesAndEventManagement\Events\DistributionAcknowledgementRejectedEvent;
use Modules\SalesAndEventManagement\Events\DistributionCancelledEvent;
use Modules\SalesAndEventManagement\Events\DistributionCompletedEvent;
use Modules\SalesAndEventManagement\Events\DistributionDistributedEvent;
use Modules\SalesAndEventManagement\Events\DistributionEscalatedEvent;
use Modules\SalesAndEventManagement\Events\DistributionSupersededEvent;
use Modules\SalesAndEventManagement\Listeners\DistributionAuditListener;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        RateLimiter::for('auth', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        RateLimiter::for('cloud_name', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });

        Event::subscribe(TaskEventListener::class);

        Event::listen(
            ApprovalRequested::class,
            [ApprovalNotificationListener::class, 'handleApprovalRequested']
        );
        Event::listen(
            ApprovalApproved::class,
            [ApprovalNotificationListener::class, 'handleApprovalApproved']
        );
        Event::listen(
            ApprovalRejected::class,
            [ApprovalNotificationListener::class, 'handleApprovalRejected']
        );
        Event::listen(
            ApprovalCancelled::class,
            [ApprovalNotificationListener::class, 'handleApprovalCancelled']
        );

        // BEO Distribution audit trail — Sprint 14.8.5.1 §3
        Event::listen(
            DistributionDistributedEvent::class,
            [DistributionAuditListener::class, 'handleDistributed']
        );
        Event::listen(
            DistributionSupersededEvent::class,
            [DistributionAuditListener::class, 'handleSuperseded']
        );
        Event::listen(
            DistributionCancelledEvent::class,
            [DistributionAuditListener::class, 'handleCancelled']
        );
        Event::listen(
            DistributionAcknowledgedEvent::class,
            [DistributionAuditListener::class, 'handleAcknowledged']
        );
        Event::listen(
            DistributionAcknowledgementRejectedEvent::class,
            [DistributionAuditListener::class, 'handleAcknowledgementRejected']
        );
        Event::listen(
            DistributionEscalatedEvent::class,
            [DistributionAuditListener::class, 'handleEscalated']
        );
        Event::listen(
            DistributionCompletedEvent::class,
            [DistributionAuditListener::class, 'handleCompleted']
        );
    }
}
