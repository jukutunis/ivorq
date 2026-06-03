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

    public function showLoginForm(): Response
    {
        return Inertia::render('Foundation/Auth/Login');
    }

    public function login(LoginRequest $request): JsonResponse|RedirectResponse
    {
        $result = $this->authService->login(
            $request->email,
            $request->password,
            $request->device_name ?? $request->userAgent() ?? 'web'
        );

        if ($request->wantsJson()) {
            return response()->json([
                'user'  => new UserResource($result['user']),
                'token' => $result['token'],
            ]);
        }

        // Web login: establish a session so the user is authenticated on subsequent
        // requests. AuthService only creates a Sanctum token; the session guard
        // must be logged in separately for Inertia/web flows.
        Auth::login($result['user']);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }
}
