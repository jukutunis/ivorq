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
    }
}
