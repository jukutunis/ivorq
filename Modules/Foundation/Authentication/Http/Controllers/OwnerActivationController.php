<?php

namespace Modules\Foundation\Authentication\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Notifications\OwnerActivationNotification;
use Modules\Foundation\Authentication\Services\OwnerActivationService;
use Modules\Foundation\User\Models\User;

class OwnerActivationController extends Controller
{
    public function __construct(private OwnerActivationService $activations) {}

    public function verifyEmail(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);
        $this->limit('activation-verify:ip:'.$request->ip(), 5, 60);
        $this->limit('activation-verify:'.$this->activations->tokenRateLimitSubject($data['token'], 'verify_email'), 5, 3600);

        return response()->json(['challenge' => $this->activations->redeemEmail($data['token'], 'verify_email')]);
    }

    public function requestResume(Request $request): JsonResponse
    {
        $data = $request->validate([
            'email' => ['required', 'email'],
            'environment' => ['required', 'in:operational,rehearsal'],
            'installation_id' => ['required', 'string', 'max:100'],
        ]);
        $email = mb_strtolower(trim($data['email']));
        $this->limit('activation-resume:ip:'.$request->ip(), 5, 3600);
        $this->limit('activation-resume:identity:'.hash('sha256', $data['environment']."\0".$data['installation_id']."\0".$email), 3, 3600);

        $activation = OwnerActivation::query()
            ->where('canonical_email', $email)
            ->where('environment', $data['environment'])
            ->where('installation_id', $data['installation_id'])
            ->where('status', '<>', 'ACTIVE')
            ->first();
        if ($activation && (app()->environment('testing') || config('mail.default') !== 'log')) {
            try {
                $token = $this->activations->issueToken($activation, 'resume_activation');
                $user = User::query()->find($activation->user_id);
                if ($user) {
                    Notification::route('mail', $activation->canonical_email)->notify(new OwnerActivationNotification($token));
                }
            } catch (ValidationException) {
                // Preserve the same outward response for every ineligible activation.
            }
        }

        return response()->json(['message' => 'If an eligible activation exists, continuation instructions will be sent.']);
    }

    public function redeemResume(Request $request): JsonResponse
    {
        $data = $request->validate(['token' => ['required', 'string', 'max:100']]);

        return response()->json(['challenge' => $this->activations->redeemEmail($data['token'], 'resume_activation')]);
    }

    public function password(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'password' => ['required', 'string', 'max:128']]);
        $this->activations->establishPassword($data['challenge'], $data['password']);

        return response()->json(['message' => 'Password established.']);
    }

    public function enrollMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string']]);

        return response()->json(['otpauth_uri' => $this->activations->startMfa($data['challenge'])]);
    }

    public function confirmMfa(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'size:6']]);
        $this->activations->confirmMfa($data['challenge'], $data['code']);

        return response()->json(['message' => 'MFA enrolled.']);
    }

    public function complete(Request $request): JsonResponse
    {
        $data = $request->validate(['challenge' => ['required', 'string'], 'code' => ['required', 'string', 'size:6']]);

        return response()->json([
            'recovery_codes' => $this->activations->complete($data['challenge'], $data['code']),
            'message' => 'Owner activation complete. Save these recovery codes now; they cannot be displayed again.',
        ]);
    }

    private function limit(string $key, int $maximum, int $decay): void
    {
        if (RateLimiter::tooManyAttempts($key, $maximum)) {
            throw ValidationException::withMessages(['request' => ['Too many attempts. Try again later.']]);
        }
        RateLimiter::hit($key, $decay);
    }
}
