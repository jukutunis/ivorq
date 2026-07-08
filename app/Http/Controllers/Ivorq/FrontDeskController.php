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
}
