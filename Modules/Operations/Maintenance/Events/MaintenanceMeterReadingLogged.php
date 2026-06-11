<?php

namespace Modules\Operations\Maintenance\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Maintenance\Models\MaintenanceMeterReading;

class MaintenanceMeterReadingLogged
{
    use Dispatchable, SerializesModels;

    public function __construct(public MaintenanceMeterReading $reading) {}
}
