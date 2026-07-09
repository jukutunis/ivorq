<?php

namespace Modules\Operations\FrontDesk\Services;

use DomainException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskDepartureClosureReadinessStatusEnum;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureClosureReadinessService
{
    public const CREATE_PERMISSION = 'frontdesk.departure-closure-readiness.create';

    private const ALLOWED_READINESS_STATUSES = [
        'CLOSURE_READY',
        'CLOSURE_BLOCKED',
        'CLOSURE_REVIEWED',
    ];

    private const MAX_NOTE_LENGTH = 2000;

    /**
     * @return array{readiness: FrontDeskDepartureClosureReadiness, replayed: bool}
     */
    public function create(
        User $actor,
        string $frontDeskStayId,
        string $readinessStatus,
        ?string $readinessNote,
        string $idempotencyKey
    ): array {
        $propertyId = $this->authorizeCreate($actor);

        $this->validateReadinessStatus($readinessStatus);
        $this->validateNoteLength($readinessNote);

        return DB::transaction(function () use (
            $actor, $propertyId, $frontDeskStayId, $readinessStatus, $readinessNote, $idempotencyKey
        ) {
            $stay = $this->lockStay($frontDeskStayId, $propertyId);

            $existing = $this->findIdempotentDuplicate($propertyId, $idempotencyKey);
            if ($existing) {
                return ['readiness' => $existing, 'replayed' => true];
            }

            $latestHandover = $this->latestHandoverForStay($propertyId, $frontDeskStayId);

            $this->validateClosureReadinessAgainstHandover($readinessStatus, $latestHandover, $readinessNote);

            $occurredAt = Carbon::now();
            $sourceHash = $this->computeSourceHash($frontDeskStayId, $readinessStatus, $readinessNote ?? '', $occurredAt);

            $sourceDuplicate = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
                ->where('property_id', $propertyId)
                ->where('front_desk_stay_id', $frontDeskStayId)
                ->where('source_hash', $sourceHash)
                ->first();

            if ($sourceDuplicate) {
                return ['readiness' => $sourceDuplicate, 'replayed' => true];
            }

            $readiness = FrontDeskDepartureClosureReadiness::create([
                'property_id' => $propertyId,
                'front_desk_stay_id' => $stay->id,
                'reservation_id' => $stay->reservation_id,
                'guest_id' => $stay->guest_id,
                'room_id' => $stay->current_room_id,
                'readiness_status' => $readinessStatus,
                'readiness_note' => $readinessNote,
                'occurred_at' => $occurredAt,
                'created_by' => $actor->id,
                'idempotency_key' => $idempotencyKey,
                'source_hash' => $sourceHash,
            ]);

            return ['readiness' => $readiness, 'replayed' => false];
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
            throw new DomainException('Departure closure readiness requires an IN_HOUSE stay.');
        }

        return $stay;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function latestHandoverForStay(string $propertyId, string $stayId): ?array
    {
        $handover = FrontDeskDepartureOperationalHandover::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $stayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->first();

        if (! $handover) {
            return null;
        }

        return [
            'id' => $handover->id,
            'handover_status' => $handover->handover_status?->value,
            'handover_note' => $handover->handover_note,
            'occurred_at' => $handover->occurred_at?->toISOString(),
            'source_hash' => $handover->source_hash,
        ];
    }

    private function validateClosureReadinessAgainstHandover(
        string $readinessStatus,
        ?array $latestHandover,
        ?string $readinessNote
    ): void {
        if ($readinessStatus === 'CLOSURE_READY') {
            if ($latestHandover === null) {
                throw new DomainException(
                    'CLOSURE_READY requires at least one FD-B3 operational handover. No handover evidence found.'
                );
            }

            if ($latestHandover['handover_status'] === 'OPERATIONAL_HANDOVER_BLOCKED') {
                throw new DomainException(
                    'CLOSURE_READY requires the latest FD-B3 operational handover to not be blocked.'
                );
            }
        }

        // CLOSURE_REVIEWED or CLOSURE_BLOCKED without B3 evidence is allowed
        // but the service records why readiness is not final.
        if ($latestHandover === null && ($readinessStatus === 'CLOSURE_REVIEWED' || $readinessStatus === 'CLOSURE_BLOCKED')) {
            // Allowed — caller may include a note explaining why readiness is not final.
            // No automatic rejection.
        }
    }

    private function findIdempotentDuplicate(string $propertyId, string $idempotencyKey): ?FrontDeskDepartureClosureReadiness
    {
        return FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->where('property_id', $propertyId)
            ->where('idempotency_key', $idempotencyKey)
            ->first();
    }

    private function computeSourceHash(string $stayId, string $readinessStatus, string $note, Carbon $occurredAt): string
    {
        return hash('sha256', implode('|', [
            $stayId,
            $readinessStatus,
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
            throw new HttpException(403, 'Front Desk departure closure readiness create permission is required.');
        }

        return $propertyId;
    }

    private function validateReadinessStatus(string $readinessStatus): void
    {
        $enum = FrontDeskDepartureClosureReadinessStatusEnum::tryFrom($readinessStatus);

        if (! $enum) {
            throw new DomainException(
                'Invalid readiness status. Allowed: ' . implode(', ', self::ALLOWED_READINESS_STATUSES) . '.'
            );
        }

        if (! in_array($readinessStatus, self::ALLOWED_READINESS_STATUSES, true)) {
            throw new DomainException(
                'Readiness status not allowed for departure closure readiness: ' . $readinessStatus
            );
        }
    }

    private function validateNoteLength(?string $note): void
    {
        if ($note !== null && mb_strlen($note) > self::MAX_NOTE_LENGTH) {
            throw new DomainException(
                'Readiness note must not exceed ' . self::MAX_NOTE_LENGTH . ' characters.'
            );
        }
    }
}
