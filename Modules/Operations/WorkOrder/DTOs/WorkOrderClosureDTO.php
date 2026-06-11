<?php

namespace Modules\Operations\WorkOrder\DTOs;

use Illuminate\Http\Request;

class WorkOrderClosureDTO
{
    public function __construct(
        public readonly string $workOrderId,
        public readonly string $resolutionNotes,
        public readonly ?string $rootCause = null,
        public readonly bool $hasSignature = false,
    ) {}

    public static function fromRequest(string $workOrderId, Request $request): self
    {
        return new self(
            workOrderId: $workOrderId,
            resolutionNotes: $request->input('resolution_notes'),
            rootCause: $request->input('root_cause'),
            hasSignature: $request->boolean('has_signature', false),
        );
    }
}
