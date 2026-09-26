<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Services\IdentitySecurityEventService;
use Modules\Foundation\Authentication\Services\OwnerAuthenticationService;
use Modules\Foundation\Authentication\Services\TokenService;
use Modules\Foundation\User\Http\Resources\UserResource;
use Modules\Foundation\User\Models\User;

class OwnerMfaController extends Controller
{
    public function __construct(
        private OwnerAuthenticationService $authentication,
        private TokenService $tokens,
        private IdentitySecurityEventService $events,
    ) {}

    public function totp(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'size:6'],
            'channel' => ['required', 'in:web,api'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $verified = $this->authentication->completeTotp($data['challenge'], $data['code'], $data['channel'], $request->hasSession() ? $request->session()->getId() : null);

        return $this->finish($request, $verified, $data['channel'], $data['device_name'] ?? 'owner');
    }

    public function recoveryCode(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'challenge' => ['required', 'string'],
            'code' => ['required', 'string', 'max:64'],
            'channel' => ['required', 'in:web,api'],
            'device_name' => ['nullable', 'string', 'max:255'],
        ]);
        $verified = $this->authentication->completeRecoveryCode($data['challenge'], $data['code'], $data['channel'], $request->hasSession() ? $request->session()->getId() : null);

        return $this->finish($request, $verified, $data['channel'], $data['device_name'] ?? 'owner');
    }

    public function regenerateRecoveryCodes(Request $request): JsonResponse
    {
        $data = $request->validate([
            'password' => ['required', 'string', 'max:128'],
            'code' => ['required', 'string', 'size:6'],
        ]);
        $key = 'owner-recovery-regeneration:'.$request->user()->id;
        if (RateLimiter::tooManyAttempts($key, 2)) {
            throw ValidationException::withMessages(['request' => ['Too many attempts. Try again later.']]);
        }
        RateLimiter::hit($key, 86400);

        return response()->json([
            'recovery_codes' => $this->authentication->regenerateRecoveryCodes($request->user(), $data['password'], $data['code']),
            'message' => 'Recovery codes regenerated. All existing sessions and tokens were revoked.',
        ]);
    }

    public function reset(Request $request): JsonResponse
    {
        $activation = OwnerActivation::query()->where('user_id', $request->user()->id)->first();
        $this->events->record('OWNER_MFA_RESET_REQUESTED', 'FAILURE', [
            'actor_user_id' => $request->user()->id,
            'subject_user_id' => $request->user()->id,
            'company_id' => $activation?->company_id,
            'property_id' => $activation?->property_id,
            'activation_id' => $activation?->id,
            'reason_code' => 'HIGH_ASSURANCE_RECOVERY_REQUIRED',
        ]);

        return response()->json(['message' => 'High-assurance owner recovery is required.'], 403);
    }

    private function finish(Request $request, $verified, string $channel, string $deviceName): JsonResponse|RedirectResponse
    {
        if ($channel === 'api') {
            $user = User::query()->findOrFail($verified->userId);

            return response()->json([
                'user' => new UserResource($user),
                'token' => $this->tokens->createForVerifiedOwner($verified, $deviceName),
                'expires_in' => 28800,
            ]);
        }

        $user = User::query()->findOrFail($verified->userId);
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put([
            'active_company_id' => $verified->companyId,
            'active_property_id' => $verified->propertyId,
            'auth_epoch' => $user->auth_epoch,
        ]);
        try {
            $this->tokens->recordVerifiedOwnerWebSession($verified, $request->session()->getId(), $deviceName);
        } catch (\Throwable $exception) {
            Auth::logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw $exception;
        }

        if ($request->wantsJson()) {
            return response()->json(['user' => new UserResource($user), 'redirect' => '/frontdesk']);
        }

        return redirect()->intended('/frontdesk');
    }
}
