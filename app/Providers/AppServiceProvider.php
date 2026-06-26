<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

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
        \Illuminate\Support\Facades\RateLimiter::for('api', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(60)->by($request->user()?->id ?: $request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('auth', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(5)->by($request->ip());
        });

        \Illuminate\Support\Facades\RateLimiter::for('cloud_name', function (\Illuminate\Http\Request $request) {
            return \Illuminate\Cache\RateLimiting\Limit::perMinute(10)->by($request->ip());
        });

        \Illuminate\Support\Facades\Event::subscribe(\Modules\Foundation\Notification\Listeners\TaskEventListener::class);
        
        \Illuminate\Support\Facades\Event::listen(
            \Modules\Foundation\Approval\Events\ApprovalRequested::class,
            [\Modules\Foundation\Approval\Listeners\ApprovalNotificationListener::class, 'handleApprovalRequested']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\Foundation\Approval\Events\ApprovalApproved::class,
            [\Modules\Foundation\Approval\Listeners\ApprovalNotificationListener::class, 'handleApprovalApproved']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\Foundation\Approval\Events\ApprovalRejected::class,
            [\Modules\Foundation\Approval\Listeners\ApprovalNotificationListener::class, 'handleApprovalRejected']
        );

        // BEO Distribution audit trail — Sprint 14.8.5.1 §3
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionDistributedEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleDistributed']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionSupersededEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleSuperseded']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionCancelledEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleCancelled']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionAcknowledgedEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleAcknowledged']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionAcknowledgementRejectedEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleAcknowledgementRejected']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionEscalatedEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleEscalated']
        );
        \Illuminate\Support\Facades\Event::listen(
            \Modules\SalesAndEventManagement\Events\DistributionCompletedEvent::class,
            [\Modules\SalesAndEventManagement\Listeners\DistributionAuditListener::class, 'handleCompleted']
        );
    }
}
