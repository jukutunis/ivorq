<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Foundation\Authentication\Services\AuthService;

class LogoutController extends Controller
{
    public function __construct(
        private AuthService $authService
    ) {}

    public function logout(Request $request): JsonResponse|RedirectResponse
    {
        if ($request->wantsJson()) {
            // API path: revoke the current Sanctum token.
            $this->authService->logout($request->user());

            return response()->json(['message' => 'Logged out successfully.']);
        }

        // Web path: invalidate the session. The user may not have a Sanctum token
        // (session-only login), so call Auth::logout() rather than revoking a token.
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }

    public function logoutAll(Request $request): JsonResponse|RedirectResponse
    {
        // Revoke all Sanctum tokens regardless of channel.
        $this->authService->logoutAllDevices($request->user());

        // Invalidate "Remember Me" sessions
        $user = $request->user();
        $user->setRememberToken(\Illuminate\Support\Str::random(60));
        $user->save();

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Logged out from all devices.']);
        }

        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('login');
    }
}
