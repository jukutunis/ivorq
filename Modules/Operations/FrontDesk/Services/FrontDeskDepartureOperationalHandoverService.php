<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureOperationalHandoverStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureOperationalHandoverService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-operational-handover.create';

    private const ALLOWED_HANDOVER_STATUSES = [
        'OPERATIONAL_HANDOVER_READY',
        'OPERATIONAL_HANDOVER_BLOCKED',
        'OPERATIONAL_HANDOVER_REVIEWED',
    ];

    private const MAX_NOTE_LENGTH = 2000;

    /**
     * @return array{handover: FrontDeskDepartureOperationalHandover, replayed: bool}
     */
    public function create(
        User $actor,
        string $frontDeskStayId,
        string $handoverStatus,
        ?string $handoverNote,
        string $idempotencyKey
    ): array {
        $propertyId = $this->authorizeCreate($actor);

        $this->validateHandoverStatus($handoverStatus);
        $this->validateNoteLength($handoverNote);

        return DB::transaction(function () use (
            $actor, $propertyId, $frontDeskStayId, $handoverStatus, $handoverNote, $idempotencyKey
        ) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = $this->findIdempotentDuplicate($propertyId, $idempotencyKey);
            if ($existing) {
                return ['handover' => $existing, 'replayed' => true];
            }

            $occurredAt = Carbon::now();
            $sourceHash = $this->computeSourceHash($frontDeskStayId, $handoverStatus, $handoverNote ?? '', $occurredAt);

            $sourceDuplicate = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $frontDeskStayId)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($sourceDuplicate) {
                return ['handover' => $sourceDuplicate, 'replayed' => true];
            }

            $handover = FrontDeskDepartureOperationalHandover::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id,
                'handover_status' => $handoverStatus,
                'handover_note' => $handoverNote,
                'occurred_at' => $occurredAt,
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return ['handover' => $handover, 'replayed' => false];
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
            throw new DomainException('Departure operational handover requires an IN_HOUSE stay.');
        }

        return $stay;
    }

    private function findIdempotentDuplicate(string $propertyId, string $idempotencyKey): ?FrontDeskDepartureOperationalHandover
    {
        return FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function computeSourceHash(string $stayId, string $handoverStatus, string $note, Carbon $occurredAt): string
    {
        return hash('sha256', implode('|', [
            $stayId,
            $handoverStatus,
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
            throw new HttpException(403, 'Front Desk departure operational handover create permission is required.');
        }

        return $propertyId;
    }

    private function validateHandoverStatus(string $handoverStatus): void
    {
        $enum = FrontDeskDepartureOperationalHandoverStatusEnum::tryFrom($handoverStatus);

        if (! $enum) {
            throw new DomainException(
                'Invalid handover status. Allowed: ' . implode(', ', self::ALLOWED_HANDOVER_STATUSES) . '.'
            );
        }

        if (! in_array($handoverStatus, self::ALLOWED_HANDOVER_STATUSES, true)) {
            throw new DomainException(
                'Handover status not allowed for departure operational handover: ' . $handoverStatus
            );
        }
    }

    private function validateNoteLength(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new DomainException(
                'Handover note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.'
            );
        }
    }
}
