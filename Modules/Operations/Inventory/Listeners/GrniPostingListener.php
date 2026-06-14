<?php

namespace Modules\Operations\Inventory\Listeners;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Queue\InteractsWithQueue;
use Modules\Operations\Inventory\Events\InventoryReceiptPosted;
use Modules\Finance\GeneralLedger\Services\GrniPostingEngine;

class GrniPostingListener implements ShouldQueue
{
    use InteractsWithQueue;

    public function __construct(
        private GrniPostingEngine $engine
    ) {}

    public function handle(InventoryReceiptPosted $event): void
    {
        $this->engine->process($event->receipt);
    }
}
