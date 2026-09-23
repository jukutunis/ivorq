<?php

namespace Modules\Foundation\Authentication\Services;

use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Rules\PrivilegedOwnerPassword;

class PasswordService
{
    public function __construct(
        private SessionRevocationService $revocation,
        private IdentitySecurityEventService $events,
    ) {}

    public function sendResetLink(string $email): string
    {
        return Password::sendResetLink(['email' => $email]);
    }

    public function reset(array $credentials): string
    {
        $credentials['email'] = mb_strtolower(trim($credentials['email']));
        $status = Password::reset(
            $credentials,
            function ($user, string $password) {
                $activation = OwnerActivation::query()->where('user_id', $user->id)->first();
                if ($activation && $activation->status !== OwnerActivationStatus::Active) {
                    throw ValidationException::withMessages(['email' => ['Owner activation must be completed before password recovery.']]);
                }
                if ($activation) {
                    Validator::make(['password' => $password], ['password' => [new PrivilegedOwnerPassword]])->validate();
                }

                if ($activation) {
                    DB::transaction(function () use ($user, $password, $activation): void {
                        $user->forceFill([
                            'password' => Hash::make($password),
                            'remember_token' => Str::random(60),
                        ])->save();
                        $this->events->record('PASSWORD_RESET', 'SUCCESS', [
                            'subject_user_id' => $user->id,
                            'company_id' => $activation->company_id,
                            'property_id' => $activation->property_id,
                            'activation_id' => $activation->id,
                        ]);
                        $this->revocation->revokeAll($user, 'PASSWORD_RESET', [
                            'actor_user_id' => $user->id,
                            'company_id' => $activation->company_id,
                            'property_id' => $activation->property_id,
                        ]);
                    });
                } else {
                    $user->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();
                }

                event(new PasswordReset($user));
            }
        );

        return $status;
    }
}
