<?php

namespace Modules\Foundation\User\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\User\Http\Requests\ChangePasswordRequest;
use Modules\Foundation\User\Http\Requests\UpdateProfileRequest;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Services\ProfileService;

class ProfileController extends Controller
{
    public function __construct(
        private ProfileService $profileService
    ) {}

    public function show(): Response
    {
        return Inertia::render('Foundation/Profile/Show', [
            'user' => new UserResource(auth()->user()),
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

        if ($request->wantsJson()) {
            return response()->json(['message' => 'Password changed successfully.']);
        }

        return redirect()->back()->with('success', 'Password changed successfully.');
    }
}
