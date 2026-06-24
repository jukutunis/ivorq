<?php

namespace Modules\Foundation\Authentication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

class EnsureActivePropertyContext
{
    public function handle(Request $request, Closure $next)
    {
        if (!Auth::check()) {
            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Unauthenticated.'], 401);
            }
            return redirect()->guest(route('login'));
        }

        $propertyId = $request->session()->get('active_property_id');
        $companyId = $request->session()->get('active_company_id');

        $isValid = false;

        if ($propertyId && $companyId) {
            $user = Auth::user();
            $isValid = $user->properties()
                ->where('properties.id', $propertyId)
                ->where('company_id', $companyId)
                ->where('properties.is_active', true)
                ->where('property_user.status', 'active')
                ->exists();
        }

        if (!$isValid) {
            $request->session()->forget(['active_property_id', 'active_company_id']);

            if ($request->expectsJson() || $request->is('api/*')) {
                return response()->json(['message' => 'Property context is invalid or expired.'], 403);
            }

            if (!$request->isMethod('GET')) {
                // Cannot easily redirect POST/PUT requests to login with intended URL
                // but setting intended for GET requests is safe.
            } else {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            $tenantId = $request->session()->get('login.tenant_id');
            if ($tenantId) {
                $hasProperties = Auth::user()->properties()
                    ->where('company_id', $tenantId)
                    ->where('properties.is_active', true)
                    ->where('property_user.status', 'active')
                    ->exists();

                if ($hasProperties) {
                    $request->session()->put('login.requires_property_selection', true);
                    return redirect()->route('login');
                }
            }

            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
            return redirect()->route('login')->withErrors(['email' => 'Your access has been revoked.']);
        }

        return $next($request);
    }
}
