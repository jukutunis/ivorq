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
use Modules\Foundation\User\Models\User;

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
                $resetUser = DB::transaction(function () use ($user, $password): User {
                    $locked = User::query()->whereKey($user->id)->lockForUpdate()->firstOrFail();
                    $activation = OwnerActivation::query()->where('user_id', $locked->id)->first();
                    if ($activation && $activation->status !== OwnerActivationStatus::Active) {
                        throw ValidationException::withMessages(['email' => ['Owner activation must be completed before password recovery.']]);
                    }
                    if ($activation) {
                        Validator::make(['password' => $password], ['password' => [new PrivilegedOwnerPassword]])->validate();
                    }

                    $locked->forceFill([
                        'password' => Hash::make($password),
                        'remember_token' => Str::random(60),
                    ])->save();

                    if ($activation) {
                        $this->events->record('PASSWORD_RESET', 'SUCCESS', [
                            'subject_user_id' => $locked->id,
                            'company_id' => $activation->company_id,
                            'property_id' => $activation->property_id,
                            'activation_id' => $activation->id,
                        ]);
                        $this->revocation->revokeAll($locked, 'PASSWORD_RESET', [
                            'actor_user_id' => $locked->id,
                            'company_id' => $activation->company_id,
                            'property_id' => $activation->property_id,
                        ]);
                    }

                    return $locked->fresh();
                });

                $user->forceFill([
                    'password' => $resetUser->password,
                    'remember_token' => $resetUser->remember_token,
                    'auth_epoch' => $resetUser->auth_epoch,
                ]);
                event(new PasswordReset($resetUser));
            }
        );

        return $status;
    }
}
