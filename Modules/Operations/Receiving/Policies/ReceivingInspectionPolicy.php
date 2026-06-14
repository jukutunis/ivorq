<?php

namespace Modules\Operations\Receiving\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Receiving\Models\ReceivingInspection;
use Shared\Services\CurrentPropertyService;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReceivingInspectionPolicy
{
    use HandlesAuthorization;

    public function __construct(protected CurrentPropertyService $propertyService) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('receiving_inspection.view');
    }

    public function view(User $user, ReceivingInspection $inspection): bool
    {
        if ($inspection->receivingLine->receivingDocument->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving_inspection.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('receiving_inspection.create');
    }
}
