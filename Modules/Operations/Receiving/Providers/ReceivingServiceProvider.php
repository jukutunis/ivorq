<?php

namespace Modules\Operations\Receiving\Providers;

use Illuminate\Support\ServiceProvider;

use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Operations\Receiving\Listeners\ReceivingApprovalListener;
use Illuminate\Support\Facades\Event;

class ReceivingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Bindings
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../Database/Migrations');

        Event::listen(ApprovalRequested::class, [ReceivingApprovalListener::class, 'handleRequested']);
        Event::listen(ApprovalApproved::class, [ReceivingApprovalListener::class, 'handleApproved']);
        Event::listen(ApprovalRejected::class, [ReceivingApprovalListener::class, 'handleRejected']);
        Event::listen(ApprovalCancelled::class, [ReceivingApprovalListener::class, 'handleCancelled']);
    }
}
