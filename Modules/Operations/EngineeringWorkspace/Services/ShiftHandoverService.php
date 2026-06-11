<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;

class ShiftHandoverService
{
    public function getShiftHandover(User $user): array
    {
        return [
            'open_handover' => [],
            'pending_acknowledgement' => [],
            'acknowledged_handover' => [],
            'critical_notes' => [],
            'unread_items' => 0,
        ];
    }
}
