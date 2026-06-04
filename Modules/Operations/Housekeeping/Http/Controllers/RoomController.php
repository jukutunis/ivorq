<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\RoomCleanlinessStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomOccupancyStatusEnum;
use Modules\Operations\Housekeeping\Enums\RoomTypeEnum;
use Modules\Operations\Housekeeping\Http\Requests\ChangeRoomCleanlinessRequest;
use Modules\Operations\Housekeeping\Http\Requests\ChangeRoomOccupancyRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreRoomRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateRoomRequest;
use Modules\Operations\Housekeeping\Http\Resources\RoomResource;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Services\RoomService;
use Shared\Services\CurrentPropertyService;

class RoomController extends Controller
{
    public function __construct(
        private RoomService $roomService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Room::class);

        $rooms = $this->roomService->paginate();

        return Inertia::render('Operations/Housekeeping/Rooms/Index', [
            'rooms' => RoomResource::collection($rooms),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Room::class);

        return Inertia::render('Operations/Housekeeping/Rooms/Create', [
            'room_types' => array_map(
                fn(RoomTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreRoomRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $room = $this->roomService->create($data);

        return redirect()->route('operations.rooms.show', $room->id)
            ->with('success', 'Room created successfully.');
    }

    public function show(string $room): Response
    {
        $model = $this->roomService->find($room);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Housekeeping/Rooms/Show', [
            'room' => new RoomResource($model),
        ]);
    }

    public function edit(string $room): Response
    {
        $model = $this->roomService->find($room);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Rooms/Edit', [
            'room'       => new RoomResource($model),
            'room_types' => array_map(
                fn(RoomTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateRoomRequest $request, string $room): RedirectResponse
    {
        $this->roomService->update($room, $request->validated());

        return redirect()->route('operations.rooms.show', $room)
            ->with('success', 'Room updated successfully.');
    }

    public function destroy(string $room): RedirectResponse
    {
        $model = $this->roomService->find($room);
        $this->authorize('delete', $model);

        $this->roomService->delete($room);

        return redirect()->route('operations.rooms.index')
            ->with('success', 'Room deleted successfully.');
    }

    public function changeCleanliness(ChangeRoomCleanlinessRequest $request, string $room): JsonResponse
    {
        $data    = $request->validated();
        $status  = RoomCleanlinessStatusEnum::from($data['cleanliness_status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->roomService->changeCleanlinessStatus($room, $status, $remarks);

        return response()->json([
            'message' => "Room cleanliness changed to {$status->label()}.",
            'room'    => new RoomResource($updated),
        ]);
    }

    public function changeOccupancy(ChangeRoomOccupancyRequest $request, string $room): JsonResponse
    {
        $data    = $request->validated();
        $status  = RoomOccupancyStatusEnum::from($data['occupancy_status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->roomService->changeOccupancyStatus($room, $status, $remarks);

        return response()->json([
            'message' => "Room occupancy changed to {$status->label()}.",
            'room'    => new RoomResource($updated),
        ]);
    }
}
