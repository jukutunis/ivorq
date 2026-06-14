<?php

namespace Modules\Operations\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Operations\Inventory\Events\InventoryAdjustmentPosted;
use Modules\Finance\GeneralLedger\Services\VariancePostingEngine;

class VariancePostingListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private VariancePostingEngine $engine
    ) {}

    public function handle(InventoryAdjustmentPosted $event): void
    {
        $this->engine->process($event->transaction);
    }
}
