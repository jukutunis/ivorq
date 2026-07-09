<?php

namespace Modules\Operations\FrontDesk\Services;

use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;
use Modules\Operations\FrontDesk\Enums\FrontDeskStayStatusEnum;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureClosureReadiness;
use Modules\Operations\FrontDesk\Models\FrontDeskDepartureOperationalHandover;
use Modules\Operations\FrontDesk\Models\FrontDeskStay;
use Shared\Services\CurrentPropertyService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class FrontDeskDepartureClosureReadinessProjectionService
{
    public const VIEW_PERMISSION = 'frontdesk.departure-preparation.view';

    /**
     * @return array<string, mixed>
     */
    public function readiness(User $actor, string $frontDeskStayId): array
    {
        $propertyId = $this->authorizeView($actor);

        $stay = FrontDeskStay::withoutGlobalScopes()
            ->whereKey($frontDeskStayId)
            ->where('property_id', $propertyId)
            ->where('status', FrontDeskStayStatusEnum::InHouse->value)
            ->first();

        if (! $stay) {
            throw new HttpException(404, 'Front Desk stay not found.');
        }

        $readinessEntries = FrontDeskDepartureClosureReadiness::withoutGlobalScopes()
            ->with(['createdBy'])
            ->where('property_id', $propertyId)
            ->where('front_desk_stay_id', $frontDeskStayId)
            ->orderBy('occurred_at', 'desc')
            ->orderBy('created_at', 'desc')
            ->limit(50)
            ->get()
            ->map(fn (FrontDeskDepartureClosureReadiness $entry) => [
                'id' => $entry->id,
                'readiness_status' => $entry->readiness_status?->value,
                'readiness_status_label' => $this->readinessStatusLabel($entry->readiness_status?->value),
                'readiness_note' => $entry->readiness_note,
                'occurred_at' => $entry->occurred_at?->toISOString(),
                'created_by' => $entry->created_by,
                'created_by_name' => $entry->createdBy?->name,
                'source_hash' => $entry->source_hash,
            ])
            ->values()
            ->all();

        $latestReadiness = $readinessEntries[0] ?? null;

        $b3Handover = $this->projectB3HandoverDependency($propertyId, $frontDeskStayId);

        $canCreate = $actor->can(FrontDeskDepartureClosureReadinessService::CREATE_PERMISSION);

        return [
            'stay_id' => $frontDeskStayId,
            'property_id' => $propertyId,
            'latest_readiness' => $latestReadiness,
            'readiness_history' => $readinessEntries,
            'readiness_count' => count($readinessEntries),
            'b3_handover_dependency' => $b3Handover,
            'b3_blocked' => $b3Handover
                ? ($b3Handover['handover_status'] === 'OPERATIONAL_HANDOVER_BLOCKED')
                : false,
            'b3_exists' => $b3Handover !== null,
            'actions' => [
                'can_create_closure_readiness' => $canCreate,
            ],
            'allowed_readiness_statuses' => $canCreate ? [
                ['value' => 'CLOSURE_READY', 'label' => 'Mark Closure Ready'],
                ['value' => 'CLOSURE_BLOCKED', 'label' => 'Mark Closure Blocked'],
                ['value' => 'CLOSURE_REVIEWED', 'label' => 'Mark Closure Reviewed'],
            ] : [],
            'closure_readiness_warning' => !$b3Handover
                ? 'No FD-B3 operational handover evidence exists. CLOSURE_READY status requires at least one handover.'
                : ($b3Handover['handover_status'] === 'OPERATIONAL_HANDOVER_BLOCKED'
                    ? 'Latest FD-B3 operational handover is blocked. CLOSURE_READY status requires the latest handover to not be blocked.'
                    : null),
            'financial_marker' => 'Financial settlement: Not evaluated in Front Desk Package B4.',
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function projectB3HandoverDependency(string $propertyId, string $stayId): ?array
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
            'handover_status_label' => $this->handoverStatusLabel($handover->handover_status?->value),
            'handover_note' => $handover->handover_note,
            'occurred_at' => $handover->occurred_at?->toISOString(),
        ];
    }

    private function readinessStatusLabel(?string $status): string
    {
        return match ($status) {
            'CLOSURE_READY' => 'Closure Ready',
            'CLOSURE_BLOCKED' => 'Closure Blocked',
            'CLOSURE_REVIEWED' => 'Closure Reviewed',
            default => $status ?? 'Unknown',
        };
    }

    private function handoverStatusLabel(?string $status): string
    {
        return match ($status) {
            'OPERATIONAL_HANDOVER_READY' => 'Operationally Ready',
            'OPERATIONAL_HANDOVER_BLOCKED' => 'Operationally Blocked',
            'OPERATIONAL_HANDOVER_REVIEWED' => 'Reviewed',
            default => $status ?? 'Unknown',
        };
    }

    private function authorizeView(User $actor): string
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

        if (! $actor->can(self::VIEW_PERMISSION)) {
            throw new HttpException(403, 'Front Desk departure preparation view permission is required.');
        }

        return $propertyId;
    }
}
