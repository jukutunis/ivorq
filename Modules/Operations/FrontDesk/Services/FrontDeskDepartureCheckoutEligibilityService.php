<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureCheckoutEligibilityStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureCheckoutEligibility;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureCheckoutEligibilityService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-checkout-eligibility.create';

    private const ALLOWED_ELIGIBILITY_STATUSES = [
        'CHECKOUT_ELIGIBLE',
        'CHECKOUT_BLOCKED',
        'CHECKOUT_REVIEWED',
    ];

    private const MAX_NOTE_LENGTH = 2000;

    /**
     * @return array{eligibility: FrontDeskDepartureCheckoutEligibility, replayed: bool}
     */
    public function create(
        User $actor,
        string $frontDeskStayId,
        string $eligibilityStatus,
        ?string $eligibilityNote,
        string $idempotencyKey
    ): array {
        $propertyId = $this->authorizeCreate($actor);

        $this->validateEligibilityStatus($eligibilityStatus);
        $this->validateNoteLength($eligibilityNote);

        return DB::transaction(function () use (
            $actor, $propertyId, $frontDeskStayId, $eligibilityStatus, $eligibilityNote, $idempotencyKey
        ) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = $this->findIdempotentDuplicate($propertyId, $idempotencyKey);
            if ($existing) {
                return ['eligibility' => $existing, 'replayed' => true];
            }

            $latestB4Readiness = $this->latestClosureReadinessForStay($propertyId, $frontDeskStayId);

            $this->validateEligibilityAgainstB4($eligibilityStatus, $latestB4Readiness, $eligibilityNote);

            $occurredAt = Carbon::now();
            $sourceHash = $this->computeSourceHash($frontDeskStayId, $eligibilityStatus, $eligibilityNote ?? '', $occurredAt);

            $sourceDuplicate = FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $frontDeskStayId)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($sourceDuplicate) {
                return ['eligibility' => $sourceDuplicate, 'replayed' => true];
            }

            $eligibility = FrontDeskDepartureCheckoutEligibility::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id,
                'eligibility_status' => $eligibilityStatus,
                'eligibility_note' => $eligibilityNote,
                'occurred_at' => $occurredAt,
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return ['eligibility' => $eligibility, 'replayed' => false];
        });
    }

    private function lockStay(string $stayId, string $propertyId): FrontDeskStay
    {
        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($stayId)
            ->where('property_id', $propertyId)
            ->lockForUpdate()
            ->first();

        if (! $stay) {
            throw new DomainException('Front Desk stay not found for active property.');
        }

        if ($stay->status !== FrontDeskStayStatusEnum::InHouse) {
            throw new DomainException('Departure checkout eligibility requires an IN_HOUSE stay.');
        }

        return $stay;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestClosureReadinessForStay(string $propertyId, string $stayId): ?array
    {
        $readiness = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $readiness) {
            return null;
        }

        return [
            'id' => $readiness->id,
            'readiness_status' => $readiness->readiness_status?->value,
            'readiness_note' => $readiness->readiness_note,
            'occurred_at' => $readiness->occurred_at?->toISOString(),
            'source_hash' => $readiness->source_hash,
        ];
    }

    private function validateEligibilityAgainstB4(
        string $eligibilityStatus,
        ?array $latestB4Readiness,
        ?string $eligibilityNote
    ): void {
        if ($eligibilityStatus === 'CHECKOUT_ELIGIBLE') {
            if ($latestB4Readiness === null) {
                throw new DomainException(
                    'CHECKOUT_ELIGIBLE requires at least one FD-B4 closure readiness. No closure readiness evidence found.'
                );
            }

            if ($latestB4Readiness['readiness_status'] === 'CLOSURE_BLOCKED') {
                throw new DomainException(
                    'CHECKOUT_ELIGIBLE requires the latest FD-B4 closure readiness to not be blocked.'
                );
            }
        }
    }

    private function findIdempotentDuplicate(string $propertyId, string $idempotencyKey): ?FrontDeskDepartureCheckoutEligibility
    {
        return FrontDeskDepartureCheckoutEligibility::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function computeSourceHash(string $stayId, string $eligibilityStatus, string $note, Carbon $occurredAt): string
    {
        return hash('sha256', implode('|', [
            $stayId,
            $eligibilityStatus,
            $note,
            $occurredAt->toISOString(),
        ]));
    }

    private function authorizeCreate(User $actor): string
    {
        $propertyId = app(CurrentPropertyService::class)->resolveOrFail();
        $companyId = session('active_company_id');

        $property = Property::withoutGlobalScopes()
            ->whereKey($propertyId)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->first();

        if (! $property) {
            throw new HttpException(403, 'Active property is required.');
        }

        if (! $actor->can(self::CREATE_PERMISSION)) {
            throw new HttpException(403, 'Front Desk departure checkout eligibility create permission is required.');
        }

        return $propertyId;
    }

    private function validateEligibilityStatus(string $eligibilityStatus): void
    {
        $enum = FrontDeskDepartureCheckoutEligibilityStatusEnum::tryFrom($eligibilityStatus);

        if (! $enum) {
            throw new DomainException(
                'Invalid eligibility status. Allowed: ' . implode(', ', self::ALLOWED_ELIGIBILITY_STATUSES) . '.'
            );
        }

        if (! in_array($eligibilityStatus, self::ALLOWED_ELIGIBILITY_STATUSES, true)) {
            throw new DomainException(
                'Eligibility status not allowed for departure checkout eligibility: ' . $eligibilityStatus
            );
        }
    }

    private function validateNoteLength(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new DomainException(
                'Eligibility note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.'
            );
        }
    }
}
