<?php

namespace Modules\Operations\PMS\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\PMS\Models\GuestPaymentReversal;

class GuestPaymentVoided
{
    use Dispatchable;
    use SerializesModels;

    public function __construct(public GuestPaymentReversal $reversal) {}
}
