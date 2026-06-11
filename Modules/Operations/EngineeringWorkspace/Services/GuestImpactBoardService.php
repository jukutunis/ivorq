<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;

class GuestImpactBoardService
{
    public function getGuestImpactBoard(User $user): array
    {
        return [
            'room_ooo' => 0,
            'room_oos' => 0,
            'vip_issues' => 0,
            'guest_complaints' => 0,
            'guest_impact_wos' => [],
        ];
    }
}
