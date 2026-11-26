<?php

namespace Modules\Operations\PMS\Services;

use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

/** PMS commands require an explicit, active Property membership and team permission. */
class PmsPropertyAuthority
{
    public function __construct(private CurrentPropertyService $currentProperty) {}

    public function require(string $permission): array
    {
        $actor = auth()->user();
        abort_unless($actor instanceof User && $actor->is_active && ! $actor->trashed(), 403);
        abort_unless(DB::table('users')->where('id', $actor->id)
            ->where('is_active', true)->whereNull('deleted_at')->exists(), 403);

        $explicit = $this->currentProperty->getExplicitPropertyId();
        $sessionProperty = null;
        $sessionCompany = null;
        $compatibility = null;
        try {
            if (request()->hasSession()) {
                $sessionProperty = request()->session()->get('current_property_id');
                $sessionCompany = request()->session()->get('active_company_id');
                $compatibility = request()->session()->get('active_property_id');
            }
        } catch (\Throwable) {
            // Direct service calls may have no session, but still need explicit context.
        }

        $propertyId = $explicit ?? $sessionProperty;
        abort_unless(is_string($propertyId) && $propertyId !== '', 403);
        abort_if($explicit !== null && $sessionProperty !== null && $explicit !== $sessionProperty, 403);
        abort_if($compatibility !== null && $compatibility !== $propertyId, 403);
        abort_if($sessionProperty !== null && ! is_string($sessionCompany), 403);

        $valid = DB::table('properties')
            ->join('companies', 'companies.id', '=', 'properties.company_id')
            ->join('property_user', 'property_user.property_id', '=', 'properties.id')
            ->where('properties.id', $propertyId)
            ->when($sessionCompany !== null, fn ($q) => $q->where('properties.company_id', $sessionCompany))
            ->where('properties.is_active', true)->whereNull('properties.deleted_at')
            ->where('companies.is_active', true)->whereNull('companies.deleted_at')
            ->where('property_user.user_id', $actor->id)
            ->where('property_user.status', 'active')->exists();
        abort_unless($valid, 403);

        setPermissionsTeamId($propertyId);
        abort_unless($actor->hasPermissionTo($permission), 403);
        $this->currentProperty->setPropertyId($propertyId);

        return [$actor, $propertyId];
    }
}
