<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Password;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authentication\Http\Requests\ForgotPasswordRequest;
use Modules\Foundation\Authentication\Http\Requests\ResetPasswordRequest;
use Modules\Foundation\Authentication\Services\PasswordService;

class PasswordResetController extends Controller
{
    public function __construct(
        private PasswordService $passwordService
    ) {}

    public function showForgotForm(\Illuminate\Http\Request $request): Response|\Illuminate\Http\RedirectResponse
    {
        $tenantId = $request->session()->get('login.tenant_id');
        if (!$tenantId) {
            return redirect()->route('login')->withErrors(['cloud_name' => 'Please select a Cloud Name first to reset your password.']);
        }

        return Inertia::render('Foundation/Auth/ForgotPassword', [
            'tenant' => [
                'id' => $tenantId,
                'name' => $request->session()->get('login.tenant_name'),
            ]
        ]);
    }

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse|RedirectResponse
    {
        $tenantId = $request->session()->get('login.tenant_id');
        if (!$tenantId) {
            return back()->withErrors(['email' => 'Session expired. Please start over.']);
        }

        $user = \Modules\Foundation\User\Models\User::where('email', $request->email)
            ->whereHas('properties', function ($q) use ($tenantId) {
                $q->where('company_id', $tenantId)
                  ->where('properties.is_active', true)
                  ->where('property_user.status', 'active');
            })->first();

        if ($user) {
            $token = Password::getRepository()->create($user);
            $user->notify(new \Modules\Foundation\Authentication\Notifications\TenantAwareResetPasswordNotification($token, $tenantId));
        }

        $msg = 'If an eligible account exists, we’ll send password reset instructions.';

        if ($request->wantsJson()) {
            return response()->json(['message' => __($msg)]);
        }

        return back()->with('status', __($msg));
    }

    public function showResetForm(\Illuminate\Http\Request $request, string $token): Response
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired password reset link.');
        }

        return Inertia::render('Foundation/Auth/ResetPassword', [
            'token' => $token,
            'email' => $request->email,
            'tenant' => $request->tenant,
        ]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse|RedirectResponse
    {
        if (!$request->hasValidSignature()) {
            abort(403, 'Invalid or expired password reset link.');
        }

        $tenantId = $request->tenant;

        $user = \Modules\Foundation\User\Models\User::where('email', $request->email)
            ->whereHas('properties', function ($q) use ($tenantId) {
                $q->where('company_id', $tenantId)
                  ->where('properties.is_active', true)
                  ->where('property_user.status', 'active');
            })->first();

        if (!$user) {
            $msg = 'Your account is no longer active in this workspace.';
            return $request->wantsJson()
                ? response()->json(['message' => $msg], 422)
                : back()->withErrors(['email' => $msg]);
        }

        $status = $this->passwordService->reset($request->validated());

        if ($status === Password::PASSWORD_RESET) {
            $msg = __($status);
            return $request->wantsJson()
                ? response()->json(['message' => $msg])
                : redirect()->route('login')->with('status', $msg);
        }

        $msg = __($status);
        return $request->wantsJson()
            ? response()->json(['message' => $msg], 422)
            : back()->withErrors(['email' => $msg]);
    }
}
