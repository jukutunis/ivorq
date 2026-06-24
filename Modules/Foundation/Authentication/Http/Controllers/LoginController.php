<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authentication\Http\Requests\LoginRequest;
use Modules\Foundation\Authentication\Services\AuthService;
use Modules\Foundation\User\Http\Resources\UserResource;

class LoginController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function showLoginForm(\Illuminate\Http\Request $request): Response
    {
        $tenantId = $request->session()->get('login.tenant_id');

        if (Auth::check() && $request->session()->has('login.requires_property_selection')) {
            $user = Auth::user();
            $eligibleProperties = $user->properties()
                ->where('company_id', $tenantId)
                ->where('properties.is_active', true)
                ->wherePivot('status', 'active')
                ->get()
                ->map(function ($p) {
                    return [
                        'id' => $p->id,
                        'name' => $p->name,
                        'code' => $p->code,
                    ];
                });

            return Inertia::render('Foundation/Auth/Login', [
                'step' => 'property',
                'tenant' => [
                    'id' => $tenantId,
                    'name' => $request->session()->get('login.tenant_name'),
                    'logo' => $request->session()->get('login.tenant_logo'),
                ],
                'properties' => $eligibleProperties
            ]);
        }

        return Inertia::render('Foundation/Auth/Login', [
            'step' => $tenantId ? 'credentials' : 'cloud_name',
            'tenant' => $tenantId ? [
                'id' => $tenantId,
                'name' => $request->session()->get('login.tenant_name'),
                'logo' => $request->session()->get('login.tenant_logo'),
            ] : null,
        ]);
    }

    public function resolveTenant(\Illuminate\Http\Request $request): RedirectResponse
    {
        $request->validate(['cloud_name' => 'required|string']);
        $cloudName = trim($request->cloud_name);

        $company = \Modules\Foundation\Property\Models\Company::where('slug', $cloudName)
            ->where('is_active', true)
            ->first();

        if (!$company) {
            return back()->withErrors(['cloud_name' => 'We couldn’t continue with that Cloud Name. Please check it and try again.']);
        }

        $request->session()->put('login.tenant_id', $company->id);
        $request->session()->put('login.tenant_name', $company->name);
        $request->session()->put('login.tenant_logo', $company->logo);

        return redirect()->route('login');
    }

    public function clearTenant(\Illuminate\Http\Request $request): RedirectResponse
    {
        $request->session()->forget(['login.tenant_id', 'login.tenant_name', 'login.tenant_logo']);
        return redirect()->route('login');
    }

    public function login(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $tenantId = $request->session()->get('login.tenant_id');
        if (!$tenantId) {
            return back()->withErrors(['email' => 'We couldn’t continue. Please check your Cloud Name and try again.']);
        }

        try {
            $result = $this->authService->login(
                $request->email,
                $request->password,
                $tenantId,
                $request->device_name ?? $request->userAgent() ?? 'web'
            );
        } catch (\Illuminate\Validation\ValidationException $e) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.']
            ]);
        }

        $user = $result['user'];

        $eligibleProperties = $user->properties()
            ->where('company_id', $tenantId)
            ->where('properties.is_active', true)
            ->wherePivot('status', 'active')
            ->get();

        if ($eligibleProperties->isEmpty()) {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'email' => ['You do not have access to any properties in this organization.']
            ]);
        }

        if ($request->wantsJson()) {
            return response()->json([
                'user'  => new UserResource($user),
                'token' => $result['token'],
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        if ($eligibleProperties->count() === 1) {
            $property = $eligibleProperties->first();
            $request->session()->put('active_property_id', $property->id);
            $request->session()->put('active_company_id', $tenantId);
            return redirect()->intended('/frontdesk');
        }

        $defaultProperty = $eligibleProperties->firstWhere('pivot.is_default', true);
        if ($defaultProperty) {
            $request->session()->put('active_property_id', $defaultProperty->id);
            $request->session()->put('active_company_id', $tenantId);
            return redirect()->intended('/frontdesk');
        }

        $request->session()->put('login.requires_property_selection', true);

        // Re-store tenant context in the new session so the UI can still show it
        $request->session()->put('login.tenant_id', $tenantId);
        $request->session()->put('login.tenant_name', $request->session()->get('login.tenant_name'));
        $request->session()->put('login.tenant_logo', $request->session()->get('login.tenant_logo'));

        return redirect()->route('login');
    }

    public function selectProperty(\Illuminate\Http\Request $request): RedirectResponse
    {
        $request->validate(['property_id' => 'required|string']);

        if (!Auth::check() || !$request->session()->has('login.requires_property_selection')) {
            return redirect()->route('login');
        }

        $tenantId = $request->session()->get('login.tenant_id');
        $user = Auth::user();

        $selected = $user->properties()
            ->where('properties.id', $request->property_id)
            ->where('company_id', $tenantId)
            ->where('properties.is_active', true)
            ->wherePivot('status', 'active')
            ->first();

        if (!$selected) {
            return back()->withErrors(['property_id' => 'Invalid or unauthorized property selected.']);
        }

        $request->session()->put('active_property_id', $selected->id);
        $request->session()->put('active_company_id', $tenantId);

        $request->session()->forget(['login.requires_property_selection']);

        return redirect()->intended('/frontdesk');
    }
}
