<?php

namespace Modules\Operations\Receiving\Policies;

use Modules\Foundation\User\Models\User;
use Modules\Operations\Receiving\Models\ReceivingDocument;
use Shared\Services\CurrentPropertyService;
use Illuminate\Auth\Access\HandlesAuthorization;

class ReceivingDocumentPolicy
{
    use HandlesAuthorization;

    public function __construct(protected CurrentPropertyService $propertyService) {}

    public function viewAny(User $user): bool
    {
        return $user->hasPermissionTo('receiving.view');
    }

    public function view(User $user, ReceivingDocument $document): bool
    {
        if ($document->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving.view');
    }

    public function create(User $user): bool
    {
        return $user->hasPermissionTo('receiving.create');
    }

    public function update(User $user, ReceivingDocument $document): bool
    {
        if ($document->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving.update');
    }

    public function delete(User $user, ReceivingDocument $document): bool
    {
        if ($document->property_id !== $this->propertyService->resolveOrFail()) {
            return false;
        }
        return $user->hasPermissionTo('receiving.delete');
    }
}
