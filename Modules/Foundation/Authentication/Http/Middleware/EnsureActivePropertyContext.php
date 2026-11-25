<?php

namespace Modules\Foundation\Authentication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Foundation\Property\Models\Property;
use Shared\Services\CurrentPropertyService;

class EnsureActivePropertyContext
{
    public function handle(Request $request, Closure $next)
    {
        if (! Auth::check()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }

            return redirect()->guest(route('login'));
        }

        $propertyId = $request->session()->get('current_property_id');
        $compatibilityPropertyId = $request->session()->get('active_property_id');
        $companyId = $request->session()->get('active_company_id');
        $currentProperty = app(CurrentPropertyService::class);
        $explicitPropertyId = $currentProperty->getExplicitPropertyId();

        $isValid = false;

        if (is_string($propertyId)
            && is_string($companyId)
            && ($compatibilityPropertyId === null || $compatibilityPropertyId === $propertyId)
            && ($explicitPropertyId === null || $explicitPropertyId === $propertyId)) {
            $user = Auth::user();
            $isValid = Property::query()
                ->join('companies', 'companies.id', '=', 'properties.company_id')
                ->join('property_user', 'property_user.property_id', '=', 'properties.id')
                ->where('properties.id', $propertyId)
                ->where('properties.company_id', $companyId)
                ->where('properties.is_active', true)
                ->whereNull('properties.deleted_at')
                ->where('companies.is_active', true)
                ->whereNull('companies.deleted_at')
                ->where('property_user.user_id', $user->id)
                ->where('property_user.status', 'active')
                ->exists();
        }

        if (! $isValid) {
            $request->session()->forget(['current_property_id', 'active_property_id', 'active_company_id', 'auth_epoch']);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Property context is invalid or expired.'], 403);
            }

            if (! $request->isMethod('GET')) {
                // Cannot easily redirect POST/PUT requests to login with intended URL
                // but setting intended for GET requests is safe.
            } else {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            return redirect()->route('login')->withErrors(['email' => 'Your access has been revoked.']);
        }

        $currentProperty->setPropertyId($propertyId);

        return $next($request);
    }
}
