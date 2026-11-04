<?php

namespace Modules\Operations\Purchasing;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Modules\Foundation\Approval\Events\ApprovalApproved;
use Modules\Foundation\Approval\Events\ApprovalCancelled;
use Modules\Foundation\Approval\Events\ApprovalRejected;
use Modules\Foundation\Approval\Events\ApprovalRequested;
use Modules\Operations\Purchasing\Listeners\PurchasingApprovalListener;

class PurchasingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        //
    }

    public function boot(): void
    {
        $this->loadMigrationsFrom(__DIR__.'/database/migrations');
        $this->loadRoutesFrom(__DIR__.'/routes/api.php');
        $this->loadRoutesFrom(__DIR__.'/routes/web.php');

        Event::listen(ApprovalRequested::class, [PurchasingApprovalListener::class, 'handleRequested']);
        Event::listen(ApprovalApproved::class, [PurchasingApprovalListener::class, 'handleApproved']);
        Event::listen(ApprovalRejected::class, [PurchasingApprovalListener::class, 'handleRejected']);
        Event::listen(ApprovalCancelled::class, [PurchasingApprovalListener::class, 'handleCancelled']);
    }
}
