<?php

namespace Modules\Operations\Zoning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Zoning\Models\ZoneAssignment;

class ZoneReassigned
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly ZoneAssignment $oldAssignment,
        public readonly ZoneAssignment $newAssignment
    ) {}
}
