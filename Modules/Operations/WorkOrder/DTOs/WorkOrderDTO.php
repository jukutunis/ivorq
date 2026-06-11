<?php

namespace Modules\Operations\WorkOrder\DTOs;

use Modules\Operations\WorkOrder\Enums\WorkOrderStatusEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderPriorityEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderTypeEnum;
use Modules\Operations\WorkOrder\Enums\WorkOrderSourceTypeEnum;
use Illuminate\Http\Request;

class WorkOrderDTO
{
    public function __construct(
        public readonly string $propertyId,
        public readonly string $title,
        public readonly WorkOrderPriorityEnum $priority,
        public readonly WorkOrderTypeEnum $type,
        public readonly ?string $assetId = null,
        public readonly ?string $description = null,
        public readonly ?WorkOrderSourceTypeEnum $sourceType = null,
        public readonly ?string $sourceId = null,
        public readonly bool $hasGuestImpact = false,
        public readonly ?string $targetResolutionAt = null,
    ) {}

    public static function fromRequest(Request $request): self
    {
        return new self(
            propertyId: $request->header('X-Property-ID'),
            title: $request->input('title'),
            priority: WorkOrderPriorityEnum::from($request->input('priority')),
            type: WorkOrderTypeEnum::from($request->input('type')),
            assetId: $request->input('asset_id'),
            description: $request->input('description'),
            sourceType: $request->input('source_type') ? WorkOrderSourceTypeEnum::from($request->input('source_type')) : null,
            sourceId: $request->input('source_id'),
            hasGuestImpact: $request->boolean('has_guest_impact', false),
            targetResolutionAt: $request->input('target_resolution_at'),
        );
    }
}
