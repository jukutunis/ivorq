<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
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
use Modules\Operations\PMS\Services\ReservationService;
use Shared\Services\CurrentPropertyService;

class ReservationController extends Controller
{
    public function __construct(
        private ReservationService $reservationService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Reservation::class);

        $filters      = request()->only(['status', 'reservation_source', 'reserved_room_type', 'arrival_date', 'departure_date']);
        $reservations = $this->reservationService->paginate($filters);

        return Inertia::render('Operations/PMS/Reservations/Index', [
            'reservations' => ReservationResource::collection($reservations),
            'statuses'     => array_map(
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
        $propertyId = app(CurrentPropertyService::class)->getId();
        $seq        = Reservation::where('property_id', $propertyId)->withTrashed()->count() + 1;

        $data = array_merge($request->validated(), [
            'property_id'        => $propertyId,
            'reservation_number' => sprintf('RES-%05d', $seq),
            'status'             => ReservationStatusEnum::Tentative->value,
        ]);

        $reservation = $this->reservationService->create($data);

        return redirect()->route('operations.pms.reservations.show', $reservation->id)
            ->with('success', 'Reservation created successfully.');
    }

    public function show(string $reservation): Response
    {
        $model = $this->reservationService->find($reservation);
        $this->authorize('view', $model);

        return Inertia::render('Operations/PMS/Reservations/Show', [
            'reservation' => new ReservationResource($model),
        ]);
    }

    public function edit(string $reservation): Response
    {
        $model = $this->reservationService->find($reservation);
        $this->authorize('update', $model);

        return Inertia::render('Operations/PMS/Reservations/Edit', [
            'reservation' => new ReservationResource($model),
            'room_types'  => array_map(
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

    public function confirm(ConfirmReservationRequest $request, string $reservation): JsonResponse
    {
        $updated = $this->reservationService->confirm($reservation);

        return response()->json([
            'message'     => 'Reservation confirmed.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function cancel(CancelReservationRequest $request, string $reservation): JsonResponse
    {
        $reason  = $request->validated()['reason'] ?? null;
        $updated = $this->reservationService->cancel($reservation, $reason);

        return response()->json([
            'message'     => 'Reservation cancelled.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function noShow(NoShowReservationRequest $request, string $reservation): JsonResponse
    {
        $updated = $this->reservationService->noShow($reservation);

        return response()->json([
            'message'     => 'Reservation marked as no-show.',
            'reservation' => new ReservationResource($updated),
        ]);
    }

    public function assignRoom(AssignRoomRequest $request, string $reservation): JsonResponse
    {
        $roomId  = $request->validated()['room_id'];
        $updated = $this->reservationService->assignRoom($reservation, $roomId);

        return response()->json([
            'message'     => 'Room assigned successfully.',
            'reservation' => new ReservationResource($updated),
        ]);
    }
}
