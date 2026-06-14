<?php

namespace Modules\Operations\Receiving\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Receiving\Models\ReceivingDiscrepancy;
use Shared\Services\CurrentPropertyService;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReceivingDiscrepancyPolicy
{
    use HandlesAuthorization;

    public function __construct(protected CurrentPropertyService $propertyService) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('receiving_discrepancy.view');
    }

    public function view(User $user, ReceivingDiscrepancy $discrepancy): bool
    {
        if ($discrepancy->receivingLine->receivingDocument->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving_discrepancy.view');
    }

    public function resolve(User $user, ReceivingDiscrepancy $discrepancy): bool
    {
        if ($discrepancy->receivingLine->receivingDocument->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving_discrepancy.resolve');
    }
}
