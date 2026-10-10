<?php

namespace Modules\Foundation\Authorization\Services;

use Illuminate\Support\Facades\Notification;
use Modules\Foundation\Authentication\Enums\OwnerActivationStatus;
use Modules\Foundation\Authentication\Models\OwnerActivation;
use Modules\Foundation\Authentication\Notifications\OwnerActivationNotification;
use Modules\Foundation\Authentication\Services\OwnerActivationService;
use Modules\Foundation\Authorization\Enums\FirstTrustRunStatus;
use Modules\Foundation\Authorization\Models\FirstTrustRun;
use RuntimeException;
use Throwable;

final class FirstTrustActivationDeliveryService
{
    public function __construct(private readonly OwnerActivationService $activations) {}

    public function deliver(FirstTrustRun $run): string
    {
        $run->refresh();
        if ($run->status !== FirstTrustRunStatus::Completed) {
            throw new RuntimeException('FIRST_TRUST_ACTIVATION_DELIVERY_BEFORE_COMPLETION');
        }

        $activation = OwnerActivation::query()
            ->whereKey($run->owner_activation_id)
            ->where('first_trust_run_id', $run->id)
            ->where('user_id', $run->user_id)
            ->where('company_id', $run->company_id)
            ->where('property_id', $run->property_id)
            ->where('environment', $run->environment)
            ->where('installation_id', $run->installation_id)
            ->first();
        if ($activation === null || $activation->status !== OwnerActivationStatus::Invited) {
            throw new RuntimeException('FIRST_TRUST_OWNER_ACTIVATION_INVALID');
        }

        try {
            $token = $this->activations->issueToken($activation, 'verify_email');
            Notification::route('mail', $activation->canonical_email)
                ->notify(new OwnerActivationNotification($token));

            return 'sent';
        } catch (Throwable) {
            return 'failed_recovery_available';
        }
    }
}
