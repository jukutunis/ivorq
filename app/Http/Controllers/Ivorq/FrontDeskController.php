<?php

namespace App\Http\Controllers\Ivorq;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckInService;
use Modules\Operations\FrontDesk\Services\FrontDeskInHouseWorkspaceService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomAssignmentService;
use Modules\Operations\FrontDesk\Services\FrontDeskRoomMoveService;
use Modules\Operations\FrontDesk\Services\FrontDeskCheckoutReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureQueueProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventService;
use Modules\Operations\FrontDesk\Services\FrontDeskDeparturePreparationEventProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureOperationalHandoverProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureClosureReadinessProjectionService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityService;
use Modules\Operations\FrontDesk\Services\FrontDeskDepartureCheckoutEligibilityProjectionService;
use Modules\Operations\FrontDesk\Services\ArrivalEligibilityProjectionService;

class FrontDeskController extends Controller
{
    public function arrivals(Request $request, ArrivalEligibilityProjectionService $arrivals)
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', [
            'activeTab' => 'arrivals',
            'arrivalWorkspace' => $arrivals->workspace($request->user(), $request->only(['search', 'arrival_date'])),
        ]);
    }

    public function departures(Request $request, FrontDeskDepartureQueueProjectionService $departures)
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', [
            'activeTab' => 'departures',
            'departureWorkspace' => $departures->queue($request->user()),
        ]);
    }

    public function inHouse(Request $request, FrontDeskInHouseWorkspaceService $inHouse)
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', [
            'activeTab' => 'in_house',
            'inHouseWorkspace' => $inHouse->workspace($request->user()),
        ]);
    }

    public function roomReadiness()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'room_readiness']);
    }

    public function checkoutReadiness(Request $request, string $stay, FrontDeskCheckoutReadinessProjectionService $readiness)
    {
        try {
            $readiness = $readiness->ready($request->user(), $stay);

            return $request->expectsJson()
                ? response()->json($readiness)
                : back()->with('checkoutReadiness', $readiness);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'checkout_readiness' => [$exception->getMessage()],
            ]);
        }
    }

    public function reservationBoard()
    {
        return Inertia::render('Ivorq/FrontDesk/FrontDeskWorkspace', ['activeTab' => 'reservation_board']);
    }

    public function assignRoom(Request $request, FrontDeskRoomAssignmentService $assignments)
    {
        $validated = $request->validate([
            'reservation_id' => ['required', 'string', 'size:26'],
            'room_id' => ['required', 'string', 'size:26'],
            'assignment_reason' => ['nullable', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $assignments->assign(
                $request->user(),
                $validated['reservation_id'],
                $validated['room_id'],
                $validated['assignment_reason'] ?? null,
                $validated['idempotency_key']
            );

            return $request->expectsJson()
                ? response()->json([
                    'status' => 'ROOM_ASSIGNED',
                    'stay_id' => $result['stay']->id,
                    'assignment_id' => $result['assignment']->id,
                    'replayed' => $result['replayed'],
                ])
                : redirect()->route('frontdesk.arrivals')->with('success', 'Room assigned.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'room_assignment' => [$exception->getMessage()],
            ]);
        }
    }

    public function prepareCheckInConfirmation(Request $request, string $stay, FrontDeskCheckInService $checkIn)
    {
        $validated = $request->validate([
            'idempotency_context' => ['required', 'string', 'max:120'],
        ]);

        try {
            $hash = $checkIn->prepareConfirmation($request->user(), $stay, $validated['idempotency_context']);

            return $request->expectsJson()
                ? response()->json([
                    'status' => 'CHECK_IN_CONFIRMATION_PENDING',
                    'intent' => FrontDeskCheckInService::INTENT,
                    'commercial_evidence_hash' => $hash,
                ])
                : redirect()
                    ->route('system.sensitive-action-confirmation.index', ['intent' => FrontDeskCheckInService::INTENT])
                    ->with('success', 'Check-in confirmation prepared.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'check_in' => [$exception->getMessage()],
            ]);
        }
    }

    public function checkIn(Request $request, string $stay, FrontDeskCheckInService $checkIn)
    {
        $validated = $request->validate([
            'idempotency_context' => ['required', 'string', 'max:120'],
        ]);

        try {
            $frontDeskStay = $checkIn->checkIn($request->user(), $stay, $validated['idempotency_context']);

            return $request->expectsJson()
                ? response()->json([
                    'status' => 'IN_HOUSE',
                    'stay_id' => $frontDeskStay->id,
                    'current_room_id' => $frontDeskStay->current_room_id,
                    'checked_in_at' => $frontDeskStay->checked_in_at?->toISOString(),
                    'checked_in_by' => $frontDeskStay->checked_in_by,
                ])
                : redirect()->route('frontdesk.arrivals')->with('success', 'Guest checked in.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'check_in' => [$exception->getMessage()],
            ]);
        }
    }

    public function prepareRoomMoveConfirmation(Request $request, string $stay, FrontDeskRoomMoveService $roomMove)
    {
        $validated = $request->validate([
            'target_room_id' => ['required', 'string', 'size:26'],
            'move_reason' => ['required', 'string', 'max:500'],
            'idempotency_context' => ['required', 'string', 'max:120'],
        ]);

        try {
            $hash = $roomMove->prepareConfirmation(
                $request->user(),
                $stay,
                $validated['target_room_id'],
                $validated['move_reason'],
                $validated['idempotency_context']
            );

            return $request->expectsJson()
                ? response()->json([
                    'status' => 'ROOM_MOVE_CONFIRMATION_PENDING',
                    'intent' => FrontDeskRoomMoveService::INTENT,
                    'commercial_evidence_hash' => $hash,
                ])
                : redirect()
                    ->route('system.sensitive-action-confirmation.index', ['intent' => FrontDeskRoomMoveService::INTENT])
                    ->with('success', 'Room move confirmation prepared.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'room_move' => [$exception->getMessage()],
            ]);
        }
    }

    public function roomMove(Request $request, string $stay, FrontDeskRoomMoveService $roomMove)
    {
        $validated = $request->validate([
            'target_room_id' => ['required', 'string', 'size:26'],
            'move_reason' => ['required', 'string', 'max:500'],
            'idempotency_key' => ['required', 'string', 'max:120'],
            'idempotency_context' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $roomMove->move(
                $request->user(),
                $stay,
                $validated['target_room_id'],
                $validated['move_reason'],
                $validated['idempotency_key'],
                $validated['idempotency_context']
            );

            return $request->expectsJson()
                ? response()->json([
                    'status' => 'IN_HOUSE',
                    'stay_id' => $result['stay']->id,
                    'current_room_id' => $result['stay']->current_room_id,
                    'room_move_assignment_id' => $result['assignment']->id,
                    'replayed' => $result['replayed'],
                ])
                : redirect('/frontdesk/in-house')->with('success', 'Room move completed.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'room_move' => [$exception->getMessage()],
            ]);
        }
    }

    public function createDeparturePreparationEvent(
        Request $request,
        string $stay,
        FrontDeskDeparturePreparationEventService $eventService
    ) {
        $validated = $request->validate([
            'event_type' => ['required', 'string', 'max:100'],
            'note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $eventService->create(
                $request->user(),
                $stay,
                $validated['event_type'],
                $validated['note'] ?? null,
                $validated['idempotency_key']
            );

            return $request->expectsJson()
                ? response()->json([
                    'event_id' => $result['event']->id,
                    'event_type' => $result['event']->event_type?->value,
                    'occurred_at' => $result['event']->occurred_at?->toISOString(),
                    'replayed' => $result['replayed'],
                ])
                : back()->with('success', $result['replayed']
                    ? 'Event already recorded (idempotent).'
                    : 'Departure preparation event recorded.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_preparation_event' => [$exception->getMessage()],
            ]);
        }
    }

    public function departurePreparationEvents(
        Request $request,
        string $stay,
        FrontDeskDeparturePreparationEventProjectionService $projection
    ) {
        try {
            $actionLog = $projection->actionLog($request->user(), $stay);

            return $request->expectsJson()
                ? response()->json($actionLog)
                : back()->with('departureActionLog', $actionLog);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_action_log' => [$exception->getMessage()],
            ]);
        }
    }

    public function createDepartureOperationalHandover(
        Request $request,
        string $stay,
        FrontDeskDepartureOperationalHandoverService $handoverService
    ) {
        $validated = $request->validate([
            'handover_status' => ['required', 'string', 'max:50'],
            'handover_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $handoverService->create(
                $request->user(),
                $stay,
                $validated['handover_status'],
                $validated['handover_note'] ?? null,
                $validated['idempotency_key']
            );

            return $request->expectsJson()
                ? response()->json([
                    'handover_id' => $result['handover']->id,
                    'handover_status' => $result['handover']->handover_status?->value,
                    'occurred_at' => $result['handover']->occurred_at?->toISOString(),
                    'replayed' => $result['replayed'],
                ])
                : back()->with('success', $result['replayed']
                    ? 'Operational handover already recorded (idempotent).'
                    : 'Departure operational handover recorded.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_operational_handover' => [$exception->getMessage()],
            ]);
        }
    }

    public function departureOperationalHandover(
        Request $request,
        string $stay,
        FrontDeskDepartureOperationalHandoverProjectionService $projection
    ) {
        try {
            $handover = $projection->handover($request->user(), $stay);

            return $request->expectsJson()
                ? response()->json($handover)
                : back()->with('departureOperationalHandover', $handover);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_operational_handover' => [$exception->getMessage()],
            ]);
        }
    }

    public function createDepartureClosureReadiness(
        Request $request,
        string $stay,
        FrontDeskDepartureClosureReadinessService $readinessService
    ) {
        $validated = $request->validate([
            'readiness_status' => ['required', 'string', 'max:50'],
            'readiness_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $readinessService->create(
                $request->user(),
                $stay,
                $validated['readiness_status'],
                $validated['readiness_note'] ?? null,
                $validated['idempotency_key']
            );

            return $request->expectsJson()
                ? response()->json([
                    'readiness_id' => $result['readiness']->id,
                    'readiness_status' => $result['readiness']->readiness_status?->value,
                    'occurred_at' => $result['readiness']->occurred_at?->toISOString(),
                    'replayed' => $result['replayed'],
                ])
                : back()->with('success', $result['replayed']
                    ? 'Closure readiness already recorded (idempotent).'
                    : 'Departure closure readiness recorded.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_closure_readiness' => [$exception->getMessage()],
            ]);
        }
    }

    public function departureClosureReadiness(
        Request $request,
        string $stay,
        FrontDeskDepartureClosureReadinessProjectionService $projection
    ) {
        try {
            $readiness = $projection->readiness($request->user(), $stay);

            return $request->expectsJson()
                ? response()->json($readiness)
                : back()->with('departureClosureReadiness', $readiness);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_closure_readiness' => [$exception->getMessage()],
            ]);
        }
    }

    public function createDepartureCheckoutEligibility(
        Request $request,
        string $stay,
        FrontDeskDepartureCheckoutEligibilityService $eligibilityService
    ) {
        $validated = $request->validate([
            'eligibility_status' => ['required', 'string', 'max:50'],
            'eligibility_note' => ['nullable', 'string', 'max:2000'],
            'idempotency_key' => ['required', 'string', 'max:120'],
        ]);

        try {
            $result = $eligibilityService->create(
                $request->user(),
                $stay,
                $validated['eligibility_status'],
                $validated['eligibility_note'] ?? null,
                $validated['idempotency_key']
            );

            return $request->expectsJson()
                ? response()->json([
                    'eligibility_id' => $result['eligibility']->id,
                    'eligibility_status' => $result['eligibility']->eligibility_status?->value,
                    'occurred_at' => $result['eligibility']->occurred_at?->toISOString(),
                    'replayed' => $result['replayed'],
                ])
                : back()->with('success', $result['replayed']
                    ? 'Checkout eligibility already recorded (idempotent).'
                    : 'Departure checkout eligibility recorded.');
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_checkout_eligibility' => [$exception->getMessage()],
            ]);
        }
    }

    public function departureCheckoutEligibility(
        Request $request,
        string $stay,
        FrontDeskDepartureCheckoutEligibilityProjectionService $projection
    ) {
        try {
            $eligibility = $projection->eligibility($request->user(), $stay);

            return $request->expectsJson()
                ? response()->json($eligibility)
                : back()->with('departureCheckoutEligibility', $eligibility);
        } catch (DomainException $exception) {
            throw ValidationException::withMessages([
                'departure_checkout_eligibility' => [$exception->getMessage()],
            ]);
        }
    }
}
