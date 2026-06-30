<?php

namespace Modules\Operations\GeneralCashier\Services;

use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Facades\DB;
use Modules\Finance\GeneralLedger\Models\Account;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\GeneralCashier\Enums\CashierSessionStatusEnum;
use Modules\Operations\GeneralCashier\Models\CashierPaymentInstrument;
use Modules\Operations\GeneralCashier\Models\CashierSession;
use Shared\Services\CurrentPropertyService;
use Throwable;

class GeneralCashierOperationalFoundationService
{
    public const OPEN_PERMISSION = 'finance.general-cashier.session.open';

    public function __construct(
        private readonly CurrentPropertyService $currentPropertyService,
    ) {}

    public function openSession(?User $actor): CashierSession
    {
        return DB::transaction(function () use ($actor): CashierSession {
            $actor = $this->resolveAuthorizedActor($actor, self::OPEN_PERMISSION);
            $propertyId = $this->resolveActivePropertyId();
            $this->assertActorCanAccessProperty($actor, $propertyId);

            $existing = CashierSession::where('property_id', $propertyId)
                ->where('cashier_user_id', $actor->id)
                ->where('status', CashierSessionStatusEnum::OPEN->value)
                ->lockForUpdate()
                ->first();

            if ($existing) {
                return $existing;
            }

            $session = new CashierSession([
                'property_id' => $propertyId,
                'cashier_user_id' => $actor->id,
                'status' => CashierSessionStatusEnum::OPEN->value,
                'opened_at' => now(),
                'opened_by' => $actor->id,
            ]);
            $session->save();

            return $session->fresh();
        });
    }

    public function closeSession(string $sessionId, ?User $actor): CashierSession
    {
        return DB::transaction(function () use ($sessionId, $actor): CashierSession {
            $session = CashierSession::whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            $actor = $this->resolveActiveActor($actor);
            $this->assertActorCanAccessProperty($actor, $session->property_id);

            if ($session->cashier_user_id !== $actor->id) {
                throw new AuthorizationException('Cashier Session can only be closed by the session owner.');
            }

            if ($session->status === CashierSessionStatusEnum::CLOSED) {
                if ($session->closed_by === $actor->id) {
                    return $session->fresh();
                }

                throw new DomainException('Conflicting Cashier Session close evidence already exists.');
            }

            if ($session->status !== CashierSessionStatusEnum::OPEN) {
                throw new DomainException('Only Open Cashier Sessions can be closed.');
            }

            $session->status = CashierSessionStatusEnum::CLOSED;
            $session->closed_at = now();
            $session->closed_by = $actor->id;
            $session->save();

            return $session->fresh();
        });
    }

    public function resolveOperationalContext(string $sessionId, string $instrumentId, ?User $actor): array
    {
        return DB::transaction(function () use ($sessionId, $instrumentId, $actor): array {
            $actor = $this->resolveActiveActor($actor);

            $session = CashierSession::whereKey($sessionId)
                ->lockForUpdate()
                ->firstOrFail();

            if ($session->status !== CashierSessionStatusEnum::OPEN) {
                throw new DomainException('Cashier Session must be Open for operational context.');
            }

            if ($session->cashier_user_id !== $actor->id) {
                throw new AuthorizationException('Operational context requires the active session cashier.');
            }

            $this->assertActorCanAccessProperty($actor, $session->property_id);

            $instrument = CashierPaymentInstrument::whereKey($instrumentId)
                ->lockForUpdate()
                ->firstOrFail();

            if (!$instrument->is_active) {
                throw new DomainException('Cashier Payment Instrument is inactive.');
            }

            if ($instrument->property_id !== $session->property_id) {
                throw new DomainException('Cashier Payment Instrument property does not match the Cashier Session.');
            }

            $account = Account::whereKey($instrument->operational_gl_account_id)
                ->where('property_id', $session->property_id)
                ->where('is_active', true)
                ->lockForUpdate()
                ->first();

            if (!$account) {
                throw new DomainException('Cashier Payment Instrument operational account is unavailable.');
            }

            return [
                'property_id' => $session->property_id,
                'cashier_session_id' => $session->id,
                'cashier_payment_instrument_id' => $instrument->id,
                'instrument_type' => $instrument->type->value,
                'operational_gl_account_id' => $account->id,
            ];
        });
    }

    private function resolveAuthorizedActor(?User $actor, string $permission): User
    {
        $freshActor = $this->resolveActiveActor($actor);

        try {
            $authorized = $freshActor->can($permission);
        } catch (Throwable) {
            throw new AuthorizationException('General Cashier permission is unavailable.');
        }

        if (!$authorized) {
            throw new AuthorizationException('General Cashier permission is required.');
        }

        return $freshActor;
    }

    private function resolveActiveActor(?User $actor): User
    {
        if (!$actor) {
            throw new AuthorizationException('General Cashier action requires an active actor.');
        }

        $freshActor = User::where('id', $actor->id)
            ->where('is_active', true)
            ->first();

        if (!$freshActor) {
            throw new AuthorizationException('General Cashier action requires an active actor.');
        }

        return $freshActor;
    }

    private function resolveActivePropertyId(): string
    {
        $propertyId = $this->currentPropertyService->getPropertyId();

        if (!$propertyId) {
            throw new AuthorizationException('General Cashier action requires active property context.');
        }

        $exists = Property::where('id', $propertyId)
            ->where('is_active', true)
            ->exists();

        if (!$exists) {
            throw new AuthorizationException('General Cashier action requires active property context.');
        }

        return $propertyId;
    }

    private function assertActorCanAccessProperty(User $actor, string $propertyId): void
    {
        $hasPropertyAccess = $actor->properties()
            ->where('properties.id', $propertyId)
            ->wherePivot('status', 'active')
            ->exists();

        if (!$hasPropertyAccess) {
            throw new AuthorizationException('General Cashier action requires active property access.');
        }
    }
}
