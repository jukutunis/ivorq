<?php

namespace Modules\Operations\Housekeeping\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Modules\Operations\Housekeeping\Services\HousekeepingInspectionClaimService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;

class RoomInspectionResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $actor = $request->user();
        $task = $this->relationLoaded('task') ? $this->task : null;
        $type = $this->inspection_type instanceof \BackedEnum
            ? $this->inspection_type->value
            : (string) $this->inspection_type;
        $status = $this->status instanceof \BackedEnum ? $this->status->value : (string) $this->status;
        $isPostCleaning = $type === 'post_cleaning';
        $isCleaner = $actor && $task && $task->completed_by === $actor->id;
        $isOwner = $actor && $this->supervisor_id === $actor->id;
        $reassignment = $this->relationLoaded('claimReassignment') ? $this->claimReassignment : null;
        $effectiveClaimantId = $reassignment?->replacement_claimant_id ?? $this->supervisor_id;
        $isEffectiveOwner = $actor && $effectiveClaimantId === $actor->id;
        $canConduct = $actor && $actor->can('conduct', $this->resource);
        $canonicalClaim = $this->claim_evidence_version === HousekeepingInspectionClaimService::EVIDENCE_VERSION;
        $legacyClaim = ! $canonicalClaim && $this->supervisor_id !== null && $status === 'in_progress';
        $canDecide = $isPostCleaning && ($canonicalClaim || $legacyClaim) && $isEffectiveOwner && ! $isCleaner && $canConduct;
        $reassignmentContext = $this->getAttribute('claim_reassignment_context');

        return [
            'id' => $this->id,
            'property_id' => $this->property_id,
            'room_id' => $this->room_id,
            'cleaning_task_id' => $this->cleaning_task_id,
            'inspector_id' => $this->supervisor_id,
            'inspection_type' => [
                'value' => $this->inspection_type->value,
                'label' => $this->inspection_type->label(),
            ],
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
            ],

            // inspection_severity is nullable
            'inspection_severity' => $this->inspection_severity
                ? ['value' => $this->inspection_severity->value, 'label' => $this->inspection_severity->label()]
                : null,

            'remarks' => $this->remarks,
            'inspected_at' => $this->inspected_at,
            'claimed_at' => $this->claimed_at,
            'claim_evidence_version' => $this->claim_evidence_version,
            'created_by' => $this->created_by,
            'updated_by' => $this->updated_by,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,

            'room' => $this->whenLoaded('room', fn () => [
                'id' => $this->room->id,
                'room_number' => $this->room->room_number,
                'room_name' => $this->room->room_name,
            ]),

            'task' => $this->whenLoaded('task', fn () => $this->task
                ? [
                    'id' => $this->task->id,
                    'task_code' => $this->task->task_code,
                    'title' => $this->task->title,
                    'completed_by_name' => $this->task->relationLoaded('completedBy')
                        ? $this->task->completedBy?->name
                        : null,
                ]
                : null
            ),

            'inspector' => $this->whenLoaded('inspector', fn () => $this->inspector
                ? ['id' => $this->inspector->id, 'name' => $this->inspector->name]
                : null
            ),

            'photos' => InspectionPhotoResource::collection($this->whenLoaded('photos')),
            'claim' => [
                'state' => $canonicalClaim
                    ? 'claimed'
                    : ($legacyClaim ? 'legacy_claim' : ($status === 'pending' ? 'available' : 'unavailable')),
                'claimant_name' => $this->relationLoaded('inspector') ? $this->inspector?->name : null,
                'original_claimant_name' => $this->relationLoaded('inspector') ? $this->inspector?->name : null,
                'effective_claimant_name' => $reassignment?->replacementClaimant?->name
                    ?? ($this->relationLoaded('inspector') ? $this->inspector?->name : null),
                'is_current_actor_cleaner' => (bool) $isCleaner,
                'is_owned_by_current_actor' => (bool) $isOwner,
                'is_original_claimant_current_actor' => (bool) $isOwner,
                'is_effective_claimant_current_actor' => (bool) $isEffectiveOwner,
                'has_reassignment' => $reassignment !== null,
                'can_reassign' => (bool) ($reassignmentContext['may_intervene'] ?? false),
                'can_claim' => (bool) (
                    $isPostCleaning
                    && $status === 'pending'
                    && ! $isCleaner
                    && $this->supervisor_id === null
                    && $this->claim_evidence_version === null
                    && $canConduct
                ),
                'can_pass' => (bool) ($canDecide && $actor->can(HousekeepingRoomReadinessTransitionService::RELEASE_READY_PERMISSION)),
                'can_fail' => (bool) ($canDecide && $actor->can(HousekeepingRoomReadinessTransitionService::CLEAN_PERMISSION)),
            ],
            'reassignment' => $reassignment ? [
                'state' => 'committed',
                'original_ineligibility_code' => $reassignment->original_ineligibility_code,
                'reason' => $reassignment->reason,
                'occurred_at' => $reassignment->occurred_at,
                'intervenor_name' => $reassignment->intervenor?->name,
                'replacement_claimant_name' => $reassignment->replacementClaimant?->name,
            ] : null,
        ];
    }
}
