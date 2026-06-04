<?php

namespace Modules\Operations\Housekeeping\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Housekeeping\Models\Room;

class RoomStatusChanged
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Room        $room,
        public readonly string      $statusField, // 'cleanliness' | 'occupancy'
        public readonly string|null $from,
        public readonly string|null $to,
        public readonly ?string     $remarks
    ) {}
}
