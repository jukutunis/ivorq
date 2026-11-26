<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authentication\Http\Requests\LoginRequest;
use Modules\Foundation\Authentication\Services\AuthService;
use Modules\Foundation\Authentication\Services\SessionGovernanceService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\Property\Models\Company;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Models\User;
use Modules\Foundation\User\Models\UserSession;

class LoginController extends Controller
{
    public function __construct(
        private AuthService $authService,
        private TokenService $tokenService,
        private SessionGovernanceService $sessions,
    ) {}

    public function showLoginForm(Request $request): Response
    {
        $tenantId = $request->hasSession() ? $request->session()->get('login.tenant_id') : null;
        $tenantId ??= $request->input('company_id');

        return Inertia::render('Foundation/Auth/Login', [
            'step' => $tenantId ? 'credentials' : 'cloud_name',
            'tenant' => $tenantId ? [
                'id' => $tenantId,
                'name' => $request->session()->get('login.tenant_name'),
                'logo' => $request->session()->get('login.tenant_logo'),
            ] : null,
        ]);
    }

    public function resolveTenant(Request $request): RedirectResponse
    {
        $request->validate(['cloud_name' => 'required|string']);
        $cloudName = trim($request->cloud_name);

        $company = Company::where('slug', $cloudName)
            ->where('is_active', true)
            ->first();

        if (! $company) {
            return back()->withErrors(['cloud_name' => 'We couldn’t continue with that Cloud Name. Please check it and try again.']);
        }

        $request->session()->put('login.tenant_id', $company->id);
        $request->session()->put('login.tenant_name', $company->name);
        $request->session()->put('login.tenant_logo', $company->logo);

        return redirect()->route('login');
    }

    public function clearTenant(Request $request): RedirectResponse
    {
        $request->session()->forget(['login.tenant_id', 'login.tenant_name', 'login.tenant_logo']);

        return redirect()->route('login');
    }

    public function login(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $tenantId = $request->hasSession() ? $request->session()->get('login.tenant_id') : null;
        $tenantId ??= $request->input('company_id');
        if (! $tenantId) {
            return back()->withErrors(['email' => 'We couldn’t continue. Please check your Cloud Name and try again.']);
        }

        $accountKey = 'login-account:'.hash('sha256', $tenantId."\0".mb_strtolower(trim((string) $request->email)));
        if (RateLimiter::tooManyAttempts($accountKey, 5)) {
            throw ValidationException::withMessages(['email' => ['Too many attempts. Try again later.']]);
        }
        RateLimiter::hit($accountKey, 60);

        try {
            $result = $this->authService->login(
                $request->email,
                $request->password,
                $tenantId,
                $request->wantsJson() ? 'api' : 'web',
                $request->hasSession() ? $request->session()->getId() : null,
            );
        } catch (ValidationException $e) {
            throw ValidationException::withMessages([
                'email' => ['The provided credentials are incorrect.'],
            ]);
        }

        if ($result['mfa_required']) {
            return response()->json([
                'mfa_required' => true,
                'challenge' => $result['challenge'],
                'expires_in' => 300,
            ], 202);
        }

        $user = $result['user'];

        $eligibleProperties = $result['properties'];

        if ($eligibleProperties->isEmpty()) {
            throw ValidationException::withMessages([
                'email' => ['You do not have access to any properties in this organization.'],
            ]);
        }

        $defaultProperties = $eligibleProperties->filter(fn ($property) => (bool) $property->pivot->is_default);
        if ($defaultProperties->count() > 1) {
            throw ValidationException::withMessages([
                'email' => ['MULTIPLE_DEFAULT_PROPERTIES_REQUIRE_ADMINISTRATIVE_RESOLUTION'],
            ]);
        }

        if ($request->wantsJson()) {
            $property = $defaultProperties->first() ?? ($eligibleProperties->count() === 1 ? $eligibleProperties->first() : null);
            if (! $property) {
                throw ValidationException::withMessages(['email' => ['An explicit default property is required.']]);
            }

            return response()->json([
                'user' => new UserResource($user),
                'token' => $this->tokenService->createForPasswordAuthentication($user, $property->id, $request->device_name ?? 'api'),
                'expires_in' => 28800,
            ]);
        }

        Auth::login($user);
        $request->session()->regenerate();

        if ($eligibleProperties->count() === 1) {
            $property = $eligibleProperties->first();
            $this->establishWebSecurityContext($request, $user, $property->id, $property->company_id);
            $this->tokenService->recordWebSession($user, $property->id, $request->session()->getId());

            return redirect()->intended('/frontdesk');
        }

        $defaultProperty = $defaultProperties->first();
        if ($defaultProperty) {
            $this->establishWebSecurityContext($request, $user, $defaultProperty->id, $defaultProperty->company_id);
            $this->tokenService->recordWebSession($user, $defaultProperty->id, $request->session()->getId());

            return redirect()->intended('/frontdesk');
        }

        $request->session()->put('login.requires_property_selection', true);
        $request->session()->put('login.tenant_id', $tenantId);
        $request->session()->put('login.pending_auth_epoch', $user->auth_epoch);

        return redirect()->route('login.property');
    }

    public function showPropertySelection(Request $request): Response|RedirectResponse
    {
        $context = $this->pendingPropertySelectionContext($request);
        if ($context === null) {
            return $this->rejectPendingPropertySelection($request);
        }

        return Inertia::render('Foundation/Auth/Login', [
            'step' => 'property',
            'tenant' => [
                'id' => $context['company']->id,
                'name' => $context['company']->name,
                'logo' => $context['company']->logo,
            ],
            'properties' => $context['properties']->map(fn ($property): array => [
                'id' => $property->id,
                'name' => $property->name,
                'code' => $property->code,
            ]),
        ]);
    }

    public function selectProperty(Request $request): RedirectResponse
    {
        $request->validate(['property_id' => 'required|string']);

        $context = $this->pendingPropertySelectionContext($request);
        if ($context === null) {
            return $this->rejectPendingPropertySelection($request);
        }

        $tenantId = $context['company']->id;
        $user = $context['user'];

        $selected = $user->properties()
            ->where('properties.id', $request->property_id)
            ->where('company_id', $tenantId)
            ->where('properties.is_active', true)
            ->wherePivot('status', 'active')
            ->first();

        if (! $selected) {
            return back()->withErrors(['property_id' => 'Invalid or unauthorized property selected.']);
        }

        $this->establishWebSecurityContext($request, $user, $selected->id, $selected->company_id);
        $this->tokenService->recordWebSession($user, $selected->id, $request->session()->getId());

        $request->session()->forget([
            'login.requires_property_selection',
            'login.pending_auth_epoch',
            'login.tenant_id',
            'login.tenant_name',
            'login.tenant_logo',
        ]);

        return redirect()->intended('/frontdesk');
    }

    private function establishWebSecurityContext(Request $request, User $user, string $propertyId, string $companyId): void
    {
        $request->session()->put([
            'current_property_id' => $propertyId,
            'active_property_id' => $propertyId,
            'active_company_id' => $companyId,
            'auth_epoch' => $user->auth_epoch,
        ]);
    }

    private function pendingPropertySelectionContext(Request $request): ?array
    {
        $user = Auth::user() instanceof User ? User::query()->find(Auth::id()) : null;
        $tenantId = $request->session()->get('login.tenant_id');
        $digest = $this->sessions->currentWebDigest($request);
        if (! $user || ! $user->is_active
            || $request->session()->get('login.requires_property_selection') !== true
            || ! is_string($tenantId)
            || (int) $request->session()->get('login.pending_auth_epoch', -1) !== (int) $user->auth_epoch
            || $request->session()->has('current_property_id')
            || $request->session()->has('active_property_id')
            || $request->session()->has('active_company_id')
            || ($digest !== null && UserSession::query()->where('user_id', $user->id)->where('web_session_digest', $digest)->exists())) {
            return null;
        }

        $company = Company::query()->whereKey($tenantId)->where('is_active', true)->whereNull('deleted_at')->first();
        if (! $company) {
            return null;
        }

        $properties = $user->properties()
            ->where('company_id', $company->id)
            ->where('properties.is_active', true)
            ->whereNull('properties.deleted_at')
            ->wherePivot('status', 'active')
            ->get();

        return $properties->isEmpty() ? null : compact('user', 'company', 'properties');
    }

    private function rejectPendingPropertySelection(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
