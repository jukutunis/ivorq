<?php

namespace Modules\Foundation\Authorization\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetPermissionTeamIdMiddleware
{
    /**
     * Set the Spatie Permission team context for every authenticated request.
     *
     * Spatie Permission with teams=true scopes all role and permission checks
     * to a team_id (property_id in IVORQ). This must be set BEFORE any call to
     * hasRole(), hasPermissionTo(), or Gate::allows(). Failing to set it means
     * permission checks run against team_id=null — which only matches global
     * (super-admin) roles and will silently deny all property-scoped permissions.
     *
     * Super-admins have property_id = null. setPermissionsTeamId(null) tells
     * Spatie to skip the team filter entirely, so super-admins pass all gates.
     *
     * This middleware is registered in two places:
     *   1. Web group (bootstrap/app.php) — fires for every Inertia/web request.
     *      Works because StartSession runs first, making Auth::user() available.
     *   2. Named alias 'permission.team' — applied explicitly to API route groups
     *      AFTER auth:sanctum so the Sanctum-resolved user is available.
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($user = $request->user()) {
            // property_id is null for super-admins — Spatie treats null as "no team
            // filter", which allows super-admins to bypass all team-scoped checks.
            $teamId = $user->isSuperAdmin() ? null : app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
            \Illuminate\Support\Facades\Log::info("Setting team ID for user {$user->id} to " . var_export($teamId, true));
            setPermissionsTeamId($teamId);
        }

        return $next($request);
    }
}
