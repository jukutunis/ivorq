<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessTransitionService;
use Symfony\Component\HttpKernel\Exception\HttpException;

class HousekeepingRoomReadinessController extends Controller
{
    public function __construct(
        private readonly HousekeepingRoomReadinessTransitionService $transitionService,
        private readonly HousekeepingRoomReadinessProjectionService $projectionService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function show(Request $request, string $roomId): array
    {
        return $this->projectionService->forHousekeeping($request->user(), $roomId);
    }

    /**
     * @return JsonResponse
     */
    public function startCleaning(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateExact($request, [
                'room_id' => ['required', 'string', 'max:26'],
                'idempotency_key' => ['required', 'string', 'max:120'],
            ]);

            $transition = $this->transitionService->startCleaning(
                $request->user(),
                $validated['room_id'],
                $validated['idempotency_key'],
            );

            return response()->json([
                'id' => $transition->id,
                'property_id' => $transition->property_id,
                'room_id' => $transition->room_id,
                'from_status' => $transition->from_status,
                'to_status' => $transition->to_status,
                'transition_type' => $transition->transition_type->value,
                'occurred_at' => $transition->occurred_at->toISOString(),
            ], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @return JsonResponse
     */
    public function submitInspection(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateExact($request, [
                'room_id' => ['required', 'string', 'max:26'],
                'idempotency_key' => ['required', 'string', 'max:120'],
                'reason' => ['nullable', 'string', 'max:255'],
            ]);

            $transition = $this->transitionService->submitInspection(
                $request->user(),
                $validated['room_id'],
                $validated['idempotency_key'],
                $validated['reason'] ?? null,
            );

            return response()->json([
                'id' => $transition->id,
                'property_id' => $transition->property_id,
                'room_id' => $transition->room_id,
                'from_status' => $transition->from_status,
                'to_status' => $transition->to_status,
                'transition_type' => $transition->transition_type->value,
                'occurred_at' => $transition->occurred_at->toISOString(),
            ], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @return JsonResponse
     */
    public function releaseReady(Request $request): JsonResponse
    {
        try {
            $validated = $this->validateExact($request, [
                'room_id' => ['required', 'string', 'max:26'],
                'release_reason' => ['required', 'string', 'max:255'],
                'idempotency_context' => ['required', 'string', 'max:120'],
            ]);

            $transition = $this->transitionService->releaseReady(
                $request->user(),
                $validated['room_id'],
                $validated['release_reason'],
                $validated['idempotency_context'],
            );

            return response()->json([
                'id' => $transition->id,
                'property_id' => $transition->property_id,
                'room_id' => $transition->room_id,
                'from_status' => $transition->from_status,
                'to_status' => $transition->to_status,
                'transition_type' => $transition->transition_type->value,
                'occurred_at' => $transition->occurred_at->toISOString(),
            ], 201);
        } catch (DomainException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (HttpException $e) {
            return response()->json(['message' => $e->getMessage()], $e->getStatusCode());
        }
    }

    /**
     * @param array<string, mixed> $rules
     * @return array<string, mixed>
     */
    private function validateExact(Request $request, array $rules): array
    {
        $unknown = array_diff(array_keys($request->all()), array_keys($rules), ['_token', '_method']);
        if ($unknown !== []) {
            throw \Illuminate\Validation\ValidationException::withMessages(
                collect($unknown)->mapWithKeys(
                    fn (string $field) => [$field => 'This lifecycle authority parameter is not accepted.']
                )->all()
            );
        }

        return $request->validate($rules);
    }
}
