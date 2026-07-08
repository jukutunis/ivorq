<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityBlockService;
use Modules\Operations\Engineering\Services\EngineeringRoomAvailabilityProjectionService;

class EngineeringRoomAvailabilityController extends Controller
{
    public function __construct(
        private readonly EngineeringRoomAvailabilityProjectionService $projectionService,
        private readonly EngineeringRoomAvailabilityBlockService $blockService
    ) {}

    public function show(Request $request, string $room): JsonResponse
    {
        return response()->json([
            'engineering_availability' => $this->projectionService->forEngineering($request->user(), $room),
        ]);
    }

    public function block(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'room_id' => ['required', 'string', 'size:26'],
            'block_reason' => ['required', 'string', 'max:255'],
            'source_type' => ['nullable', 'string', 'max:100'],
            'source_id' => ['nullable', 'string', 'size:26'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'property_id' => ['prohibited'],
            'block_status' => ['prohibited'],
            'availability_status' => ['prohibited'],
            'started_at' => ['prohibited'],
            'started_by' => ['prohibited'],
            'released_at' => ['prohibited'],
            'released_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ]);

        $block = $this->blockService->block(
            $request->user(),
            $validated['room_id'],
            $validated['block_reason'],
            $validated['source_type'] ?? null,
            $validated['source_id'] ?? null,
            $validated['idempotency_key']
        );

        return response()->json([
            'engineering_room_availability_block' => $block,
        ], 201);
    }

    public function release(Request $request, string $block): JsonResponse
    {
        $validated = $request->validate([
            'release_reason' => ['required', 'string', 'max:255'],
            'idempotency_context' => ['required', 'string', 'max:120'],
            'property_id' => ['prohibited'],
            'room_id' => ['prohibited'],
            'block_status' => ['prohibited'],
            'availability_status' => ['prohibited'],
            'started_at' => ['prohibited'],
            'started_by' => ['prohibited'],
            'released_at' => ['prohibited'],
            'released_by' => ['prohibited'],
            'created_by' => ['prohibited'],
            'updated_by' => ['prohibited'],
        ]);

        $released = $this->blockService->release(
            $request->user(),
            $block,
            $validated['release_reason'],
            $validated['idempotency_context']
        );

        return response()->json([
            'engineering_room_availability_block' => $released,
        ]);
    }
}
