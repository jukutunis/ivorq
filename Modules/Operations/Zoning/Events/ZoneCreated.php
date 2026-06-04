<?php

namespace Modules\Operations\Zoning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Zoning\Models\Zone;

class ZoneCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Zone $zone
    ) {}
}
