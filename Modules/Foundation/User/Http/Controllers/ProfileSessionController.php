<?php

namespace Modules\Foundation\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Modules\Foundation\Authentication\Services\SessionGovernanceService;

class ProfileSessionController extends Controller
{
    public function __construct(private SessionGovernanceService $sessions) {}

    public function destroy(Request $request, string $session): JsonResponse|RedirectResponse
    {
        $current = $this->sessions->revokeOne($request->user(), $session, $request);
        if ($current && $request->hasSession()) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Session revoked.', 'current' => $current]);
        }

        return $current
            ? redirect()->route('login')
            : redirect()->back()->with('success', 'Session revoked.');
    }
}
