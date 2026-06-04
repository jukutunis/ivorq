<?php

namespace Modules\Operations\Zoning\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Zoning\Enums\ZoneStatusEnum;
use Modules\Operations\Zoning\Models\Zone;

class ZoneStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Zone          $zone,
        public readonly ZoneStatusEnum $from,
        public readonly ZoneStatusEnum $to,
        public readonly ?string        $remarks
    ) {}
}
