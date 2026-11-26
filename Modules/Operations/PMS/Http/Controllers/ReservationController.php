<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\PMS\Enums\ReservationSourceEnum;
use Modules\Operations\PMS\Enums\ReservationStatusEnum;
use Modules\Operations\PMS\Http\Requests\AssignRoomRequest;
use Modules\Operations\PMS\Http\Requests\CancelReservationRequest;
use Modules\Operations\PMS\Http\Requests\ConfirmReservationRequest;
use Modules\Operations\PMS\Http\Requests\NoShowReservationRequest;
use Modules\Operations\PMS\Http\Requests\StoreReservationRequest;
use Modules\Operations\PMS\Http\Requests\UpdateReservationRequest;
use Modules\Operations\PMS\Http\Resources\ReservationResource;
use Modules\Operations\PMS\Models\Reservation;
use Modules\Operations\PMS\Services\GuestService;
use Modules\Operations\PMS\Services\ReservationService;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
        private GuestService $guestService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Reservation::class);

        $filters = request()->only(['status', 'reservation_source', 'reserved_room_type', 'arrival_date', 'departure_date']);
        $reservations = $this->reservationService->paginate($filters);

        return Inertia::render('Operations/PMS/Reservations/Index', [
            'reservations' => ReservationResource::collection($reservations),
            'statuses' => array_map(
                fn (ReservationStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReservationStatusEnum::cases()
            ),
            'room_types' => array_map(
                fn (RoomTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomTypeEnum::cases()
            ),
            'sources' => array_map(
                fn (ReservationSourceEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReservationSourceEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Reservation::class);

        return Inertia::render('Operations/PMS/Reservations/Create', [
            'room_types' => array_map(
                fn (RoomTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomTypeEnum::cases()
            ),
            'sources' => array_map(
                fn (ReservationSourceEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReservationSourceEnum::cases()
            ),
        ]);
    }

    public function store(StoreReservationRequest $request): RedirectResponse
    {
        $reservation = $this->reservationService->create($request->validated());

        return redirect()->route('operations.pms.reservations.show', $reservation->id)
            ->with('success', 'Reservation created successfully.');
    }

    public function show(string $reservation): Response
    {
        $model = $this->reservationService->find($reservation);
        $this->authorize('view', $model);
        $capabilities = $this->reservationService->capabilities($model);
        $guestSearch = mb_substr(trim((string) request()->query('guest_search', '')), 0, 100);
        $guestOptions = $capabilities['can_manage_guests'] && $guestSearch !== ''
            ? $this->guestService->paginate(['search' => $guestSearch], 20)->getCollection()
                ->map(fn ($guest) => ['id' => $guest->id, 'name' => $guest->full_name, 'code' => $guest->guest_code])
                ->values()->all()
            : [];

        return Inertia::render('Operations/PMS/Reservations/Show', [
            'reservation' => new ReservationResource($model),
            'capabilities' => $capabilities,
            'guest_options' => $guestOptions,
            'guest_search' => $guestSearch,
        ]);
    }

    public function edit(string $reservation): Response
    {
        $model = $this->reservationService->find($reservation);
        $this->authorize('update', $model);

        return Inertia::render('Operations/PMS/Reservations/Edit', [
            'reservation' => new ReservationResource($model),
            'room_types' => array_map(
                fn (RoomTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomTypeEnum::cases()
            ),
            'sources' => array_map(
                fn (ReservationSourceEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReservationSourceEnum::cases()
            ),
        ]);
    }

    public function update(UpdateReservationRequest $request, string $reservation): RedirectResponse
    {
        $this->reservationService->update($reservation, $request->validated());

        return redirect()->route('operations.pms.reservations.show', $reservation)
            ->with('success', 'Reservation updated successfully.');
    }

    public function destroy(string $reservation): RedirectResponse
    {
        $model = $this->reservationService->find($reservation);
        $this->authorize('delete', $model);

        $this->reservationService->delete($reservation);

        return redirect()->route('operations.pms.reservations.index')
            ->with('success', 'Reservation deleted.');
    }

    public function confirm(ConfirmReservationRequest $request, string $reservation): JsonResponse|RedirectResponse
    {
        $updated = $this->reservationService->confirm($reservation);
        if (! $request->expectsJson()) {
            return redirect()->route('operations.pms.reservations.show', $reservation);
        }

        return response()->json([
            'message' => 'Reservation confirmed.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function cancel(CancelReservationRequest $request, string $reservation): JsonResponse|RedirectResponse
    {
        $reason = $request->validated()['reason'] ?? null;
        $updated = $this->reservationService->cancel($reservation, $reason);
        if (! $request->expectsJson()) {
            return redirect()->route('operations.pms.reservations.show', $reservation);
        }

        return response()->json([
            'message' => 'Reservation cancelled.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function noShow(NoShowReservationRequest $request, string $reservation): JsonResponse|RedirectResponse
    {
        $updated = $this->reservationService->noShow($reservation);
        if (! $request->expectsJson()) {
            return redirect()->route('operations.pms.reservations.show', $reservation);
        }

        return response()->json([
            'message' => 'Reservation marked as no-show.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function assignRoom(AssignRoomRequest $request, string $reservation): JsonResponse
    {
        $roomId = $request->validated()['room_id'];
        $updated = $this->reservationService->assignRoom($reservation, $roomId);

        return response()->json([
            'message' => 'Room assigned successfully.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function addGuest(Request $request, string $reservation): RedirectResponse
    {
        $data = $request->validate(['guest_id' => ['required', 'string', 'size:26']]);
        $this->guestService->addAccompanyingGuest($reservation, $data['guest_id']);

        return redirect()->route('operations.pms.reservations.show', $reservation);
    }

    public function removeGuest(string $reservation, string $guest): RedirectResponse
    {
        $this->guestService->removeAccompanyingGuest($reservation, $guest);

        return redirect()->route('operations.pms.reservations.show', $reservation);
    }
}
