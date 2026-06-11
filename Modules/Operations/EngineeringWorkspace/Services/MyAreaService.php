<?php

namespace Modules\Operations\EngineeringWorkspace\Services;

use Modules\Foundation\User\Models\User;

class MyAreaService
{
    public function getMyAreas(User $user): array
    {
        return [
            'areas' => [],
            'buildings' => [],
            'floors' => [],
            'rooms' => [],
            'equipment' => [],
        ];
    }
}
