<?php

namespace Modules\Operations\Inventory\Services;

use DomainException;
use Illuminate\Contracts\Auth\Guard;
use Modules\Foundation\Property\Models\PropertyBusinessDate;

class BusinessDateCloseService
{
    private Guard $auth;

    public function __construct(Guard $auth)
    {
        $this->auth = $auth;
    }

    public function close(PropertyBusinessDate $businessDate): PropertyBusinessDate
    {
        if (!$businessDate->is_open) {
            throw new DomainException('Business Date is already closed.');
        }

        $userId = $this->auth->id();

        if ($userId === null || trim((string)$userId) === '') {
            throw new DomainException('Authenticated actor is required to close Business Date.');
        }

        $businessDate->is_open = false;
        $businessDate->closed_at = now();
        $businessDate->closed_by = $userId;

        return $businessDate;
    }
}
