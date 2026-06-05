<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\PMS\Enums\RoomBlockReasonEnum;
use Modules\Operations\PMS\Enums\RoomBlockTypeEnum;
use Modules\Operations\PMS\Http\Requests\ReleaseRoomBlockRequest;
use Modules\Operations\PMS\Http\Requests\StoreRoomBlockRequest;
use Modules\Operations\PMS\Http\Requests\UpdateRoomBlockRequest;
use Modules\Operations\PMS\Http\Resources\RoomBlockResource;
use Modules\Operations\PMS\Models\RoomBlock;
use Modules\Operations\PMS\Services\RoomBlockService;
use Shared\Services\CurrentPropertyService;

class RoomBlockController extends Controller
{
    public function __construct(
        private RoomBlockService $roomBlockService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', RoomBlock::class);

        $filters = request()->only(['room_id', 'block_type', 'status']);
        $blocks  = $this->roomBlockService->paginate($filters);

        return Inertia::render('Operations/PMS/RoomBlocks/Index', [
            'room_blocks' => RoomBlockResource::collection($blocks),
            'block_types' => array_map(
                fn (RoomBlockTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomBlockTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RoomBlock::class);

        return Inertia::render('Operations/PMS/RoomBlocks/Create', [
            'block_types' => array_map(
                fn (RoomBlockTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomBlockTypeEnum::cases()
            ),
            'reasons' => array_map(
                fn (RoomBlockReasonEnum $r) => ['value' => $r->value, 'label' => $r->label()],
                RoomBlockReasonEnum::cases()
            ),
        ]);
    }

    public function store(StoreRoomBlockRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
            'status'      => \Modules\Operations\PMS\Enums\RoomBlockStatusEnum::Active->value,
        ]);

        $block = $this->roomBlockService->create($data);

        return redirect()->route('operations.pms.room-blocks.show', $block->id)
            ->with('success', 'Room block created successfully.');
    }

    public function show(string $room_block): Response
    {
        $model = $this->roomBlockService->find($room_block);
        $this->authorize('view', $model);

        return Inertia::render('Operations/PMS/RoomBlocks/Show', [
            'room_block' => new RoomBlockResource($model),
        ]);
    }

    public function edit(string $room_block): Response
    {
        $model = $this->roomBlockService->find($room_block);
        $this->authorize('update', $model);

        return Inertia::render('Operations/PMS/RoomBlocks/Edit', [
            'room_block'  => new RoomBlockResource($model),
            'block_types' => array_map(
                fn (RoomBlockTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RoomBlockTypeEnum::cases()
            ),
            'reasons' => array_map(
                fn (RoomBlockReasonEnum $r) => ['value' => $r->value, 'label' => $r->label()],
                RoomBlockReasonEnum::cases()
            ),
        ]);
    }

    public function update(UpdateRoomBlockRequest $request, string $room_block): RedirectResponse
    {
        $this->roomBlockService->update($room_block, $request->validated());

        return redirect()->route('operations.pms.room-blocks.show', $room_block)
            ->with('success', 'Room block updated successfully.');
    }

    public function destroy(string $room_block): RedirectResponse
    {
        $model = $this->roomBlockService->find($room_block);
        $this->authorize('delete', $model);

        $model->delete();

        return redirect()->route('operations.pms.room-blocks.index')
            ->with('success', 'Room block deleted.');
    }

    public function release(ReleaseRoomBlockRequest $request, string $room_block): JsonResponse
    {
        $updated = $this->roomBlockService->release($room_block);

        return response()->json([
            'message'    => 'Room block released.',
            'room_block' => new RoomBlockResource($updated),
        ]);
    }
}
