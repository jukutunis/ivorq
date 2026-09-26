<?php

namespace Modules\Foundation\Authentication\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Laravel\Sanctum\PersonalAccessToken;
use Modules\Foundation\Authentication\Services\AuthenticatedSecurityService;
use Modules\Foundation\User\Models\UserSession;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedSecurity
{
    public function __construct(private AuthenticatedSecurityService $security) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();
        if (! $user) {
            return $next($request);
        }

        $token = $user->currentAccessToken();
        if ($this->security->valid($user, $request, $token)) {
            return $next($request);
        }

        if ($token instanceof PersonalAccessToken) {
            UserSession::query()->where('token_id', $token->id)->delete();
            $token->delete();
        } elseif ($request->hasSession()) {
            $digest = hash('sha256', "IVORQ-WEB-SESSION-V1\0".$request->session()->getId());
            UserSession::query()->where('user_id', $user->id)->where('web_session_digest', $digest)->delete();
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->expectsJson() || $request->is('api/*')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return redirect()->route('login')->withErrors(['email' => 'Your session is no longer valid.']);
    }
}
