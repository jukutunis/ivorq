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

    public function showForgotForm(): Response
    {
        return Inertia::render('Foundation/Auth/ForgotPassword');
    }

    public function sendResetLink(ForgotPasswordRequest $request): JsonResponse|RedirectResponse
    {
        $status = $this->passwordService->sendResetLink($request->email);

        if ($request->wantsJson()) {
            return $status === Password::RESET_LINK_SENT
                ? response()->json(['message' => __($status)])
                : response()->json(['message' => __($status)], 422);
        }

        return $status === Password::RESET_LINK_SENT
            ? back()->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }

    public function showResetForm(string $token): Response
    {
        return Inertia::render('Foundation/Auth/ResetPassword', ['token' => $token]);
    }

    public function reset(ResetPasswordRequest $request): JsonResponse|RedirectResponse
    {
        $status = $this->passwordService->reset($request->validated());

        if ($request->wantsJson()) {
            return $status === Password::PASSWORD_RESET
                ? response()->json(['message' => __($status)])
                : response()->json(['message' => __($status)], 422);
        }

        return $status === Password::PASSWORD_RESET
            ? redirect()->route('login')->with('status', __($status))
            : back()->withErrors(['email' => __($status)]);
    }
}
