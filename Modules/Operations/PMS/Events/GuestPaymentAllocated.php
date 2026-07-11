<?php

namespace Modules\Operations\PMS\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\PMS\Models\GuestPaymentAllocation;

class GuestPaymentAllocated
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public GuestPaymentAllocation $allocation) {}
}
