<?php

namespace Modules\Finance\CostControl\Services;

use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Modules\Finance\CostControl\Models\CostDeliveryPilotProperty;
use Modules\Finance\CostControl\Repositories\CostDeliveryCutoverPreflightRepository;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use RuntimeException;

final class CostDeliveryPilotAuthorizationService
{
    public function __construct(private readonly CostDeliveryCutoverPreflightRepository $preflightRepository) {}

    public function authorize(
        string $propertyId,
        string $ownerApprovalReference,
        string $authorizedBy,
    ): CostDeliveryPilotProperty {
        if (trim($propertyId) === '') {
            throw new RuntimeException('PILOT_AUTHORIZATION_PROPERTY_NOT_FOUND');
        }
        if (trim($authorizedBy) === '') {
            throw new RuntimeException('PILOT_AUTHORIZATION_ACTOR_NOT_FOUND');
        }

        $ownerApprovalReference = trim($ownerApprovalReference);
        if ($ownerApprovalReference === '') {
            throw new InvalidArgumentException('Pilot owner approval reference cannot be blank.');
        }

        return DB::transaction(function () use (
            $propertyId,
            $ownerApprovalReference,
            $authorizedBy,
        ): CostDeliveryPilotProperty {
            // An empty relation cannot provide a row lock. This transaction-scoped
            // latch serializes first-slot creation before the canonical row locks.
            DB::select('SELECT pg_advisory_xact_lock(hashtext(?))', [
                'ivorq:cost-delivery-pilot-properties:slot:1',
            ]);

            $pilots = $this->preflightRepository->lockPilotRows();

            if ($pilots->count() > 1
                || ($pilots->count() === 1 && (int) $pilots->first()->pilot_slot !== 1)) {
                throw new RuntimeException('PILOT_AUTHORIZATION_STATE_INVALID');
            }

            $property = Property::query()->whereKey($propertyId)->lockForUpdate()->first();
            if ($property === null) {
                throw new RuntimeException('PILOT_AUTHORIZATION_PROPERTY_NOT_FOUND');
            }

            $actor = User::query()->whereKey($authorizedBy)->lockForUpdate()->first();
            if ($actor === null) {
                throw new RuntimeException('PILOT_AUTHORIZATION_ACTOR_NOT_FOUND');
            }
            if (! $actor->is_active) {
                throw new RuntimeException('PILOT_AUTHORIZATION_ACTOR_INACTIVE');
            }

            /** @var CostDeliveryPilotProperty|null $existing */
            $existing = $pilots->first();
            if ($existing !== null) {
                if ($existing->property_id === $property->id
                    && $existing->owner_approval_reference === $ownerApprovalReference
                    && $existing->authorized_by === $actor->id) {
                    return $existing;
                }

                throw new RuntimeException('PILOT_AUTHORIZATION_CONFLICT');
            }

            return CostDeliveryPilotProperty::create([
                'pilot_slot' => 1,
                'property_id' => $property->id,
                'owner_approval_reference' => $ownerApprovalReference,
                'authorized_by' => $actor->id,
                'authorized_at' => now(),
            ]);
        }, 1);
    }
}
