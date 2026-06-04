<?php

namespace Modules\Operations\Housekeeping\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Operations\Housekeeping\Models\Room;

class RoomCreated
{
    use Dispatchable, SerializesModels;

    public function __construct(
        public readonly Room $room
    ) {}
}
