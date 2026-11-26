<?php

namespace Modules\Foundation\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Authentication\Services\SessionGovernanceService;
use Modules\Foundation\User\Http\Requests\ChangePasswordRequest;
use Modules\Foundation\User\Http\Requests\UpdateProfileRequest;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Services\ProfileService;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profileService,
        private SessionGovernanceService $sessions,
    ) {}

    public function show(Request $request): Response
    {
        return Inertia::render('Foundation/Profile/Show', [
            'user' => new UserResource(auth()->user()),
            'sessions' => $this->sessions->actorSessions($request->user(), $request),
        ]);
    }

    public function update(UpdateProfileRequest $request): JsonResponse|RedirectResponse
    {
        $user = $this->profileService->update(auth()->user(), $request->validated());

        if ($request->wantsJson()) {
            return response()->json(['user' => new UserResource($user)]);
        }

        return redirect()->back()->with('success', 'Profile updated successfully.');
    }

    public function changePassword(ChangePasswordRequest $request): JsonResponse|RedirectResponse
    {
        $changed = $this->profileService->changePassword(
            auth()->user(),
            $request->current_password,
            $request->password
        );

        if (! $changed) {
            return back()->withErrors(['current_password' => 'Current password is incorrect.']);
        }

        Auth::logout();
        if ($request->hasSession()) {
            $request->session()->invalidate();
            $request->session()->regenerateToken();
        }

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Password changed successfully.']);
        }

        return redirect()->route('login')->with('success', 'Password changed successfully. Please sign in again.');
    }
}
