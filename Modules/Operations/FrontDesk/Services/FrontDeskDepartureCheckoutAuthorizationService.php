<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutAuthorizationStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutAuthorization;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutAuthorizationService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-checkout-authorization.create';

    private const ALLOWED_STATUSES = ['CHECKOUT_AUTHORIZATION_READY','CHECKOUT_AUTHORIZATION_BLOCKED','CHECKOUT_AUTHORIZATION_REVIEWED'];
    private const MAX_NOTE_LENGTH = 2000;

    public function create(User $actor, string $frontDeskStayId, string $authorizationStatus, ?string $authorizationNote, string $idempotencyKey): array
    {
        $propertyId = $this->authorizeCreate($actor);
        $this->validateStatus($authorizationStatus);
        $this->validateNoteLength($authorizationNote);

        return DB::transaction(function () use ($actor, $propertyId, $frontDeskStayId, $authorizationStatus, $authorizationNote, $idempotencyKey) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()
                ->where('property_id', $propertyId)->where('idempotency_key', $idempotencyKey)->first();
            if ($existing) return ['authorization' => $existing, 'replayed' => true];

            $latestB5 = $this->latestEligibilityForStay($propertyId, $frontDeskStayId);
            $this->validateAgainstB5($authorizationStatus, $latestB5);

            $occurredAt = Carbon::now();
            $sourceHash = hash('sha256', implode('|', [$frontDeskStayId, $authorizationStatus, $authorizationNote ?? '', $occurredAt->toISOString()]));

            $sourceDup = FrontDeskDepartureCheckoutAuthorization::withoutGlobalScopes()
                ->where('property_id', $propertyId)->where('front_desk_stay_id', $frontDeskStayId)->where('source_hash', $sourceHash)->first();
            if ($sourceDup) return ['authorization' => $sourceDup, 'replayed' => true];

            $auth = FrontDeskDepartureCheckoutAuthorization::create([
                'property_id' => $propertyId, 'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id, 'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id, 'authorization_status' => $authorizationStatus,
                'authorization_note' => $authorizationNote, 'occurred_at' => $occurredAt,
                'created_by' => $actor->id, 'idempotency_key' => $idempotencyKey, 'source_hash' => $sourceHash,
            ]);
            return ['authorization' => $auth, 'replayed' => false];
        });
    }

    private function lockStay(string $stayId, string $propertyId): FrontDeskStay
    {
        $stay = FrontDeskStay::withoutGlobalScopes()->whereKey($stayId)->where('property_id', $propertyId)->lockForUpdate()->first();
        if (!$stay) throw new DomainException('Front Desk stay not found for active property.');
        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) throw new DomainException('Departure checkout authorization requires an IN_HOUSE stay.');
        return $stay;
    }

    private function latestEligibilityForStay(string $propertyId, string $stayId): ?array
    {
        $e = FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->where('property_id', $propertyId)->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')->orderBy('created_at', 'desc')->first();
        return $e ? ['id' => $e->id, 'eligibility_status' => $e->eligibility_status?->value, 'eligibility_note' => $e->eligibility_note] : null;
    }

    private function validateAgainstB5(string $status, ?array $latestB5): void
    {
        if ($status === 'CHECKOUT_AUTHORIZATION_READY') {
            if ($latestB5 === null) throw new DomainException('CHECKOUT_AUTHORIZATION_READY requires at least one FD-B5 checkout eligibility. No eligibility evidence found.');
            if ($latestB5['eligibility_status'] !== 'CHECKOUT_ELIGIBLE') throw new DomainException('CHECKOUT_AUTHORIZATION_READY requires the latest FD-B5 checkout eligibility to be CHECKOUT_ELIGIBLE.');
        }
    }

    private function authorizeCreate(User $actor): string
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');
        $property = Property::withoutGlobalScopes()->whereKey($propertyId)->where('company_id', $companyId)->where('is_active', true)->first();
        if (!$property) throw new HttpException(403, 'Active property is required.');
        if (!$actor->can(self::CREATE_PERMISSION)) throw new HttpException(403, 'Front Desk departure checkout authorization create permission is required.');
        return $propertyId;
    }

    private function validateStatus(string $s): void
    {
        if (!FrontDeskDepartureCheckoutAuthorizationStatusEnum::tryFrom($s) || !in_array($s, self::ALLOWED_STATUSES, true))
            throw new DomainException('Invalid authorization status. Allowed: ' . implode(', ', self::ALLOWED_STATUSES) . '.');
    }

    private function validateNoteLength(?string $n): void
    {
        if ($n !== null && mb_strlen($n) > self::MAX_NOTE_LENGTH) throw new DomainException('Authorization note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.');
    }
}
