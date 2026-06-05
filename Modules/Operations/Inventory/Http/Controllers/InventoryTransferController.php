<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\TransferStatusEnum;
use Modules\Operations\Inventory\Http\Requests\CancelTransferRequest;
use Modules\Operations\Inventory\Http\Requests\CompleteTransferRequest;
use Modules\Operations\Inventory\Http\Requests\StoreTransferRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateTransferRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryTransferResource;
use Modules\Operations\Inventory\Models\InventoryTransfer;
use Modules\Operations\Inventory\Repositories\InventoryTransferRepository;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Modules\Operations\Inventory\Services\TransferService;
use Shared\Services\CurrentPropertyService;

class InventoryTransferController extends Controller
{
    public function __construct(
        private TransferService $transferService,
        private InventoryTransferRepository $transferRepository,
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryTransfer::class);

        $filters   = request()->only(['status', 'from_location_id', 'to_location_id']);
        $transfers = $this->transferRepository->paginate($filters);

        return Inertia::render('Operations/Inventory/Transfers/Index', [
            'transfers' => InventoryTransferResource::collection($transfers),
            'statuses'  => array_map(
                fn(TransferStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                TransferStatusEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryTransfer::class);

        return Inertia::render('Operations/Inventory/Transfers/Create', [
            'items'     => $this->masterDataService->paginateItems([], 500)->items(),
            'locations' => $this->masterDataService->paginateLocations([], 500)->items(),
        ]);
    }

    public function store(StoreTransferRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id'  => app(CurrentPropertyService::class)->getId(),
            'requested_by' => auth()->id(),
        ]);

        $transfer = $this->transferService->create($data);

        return redirect()->route('operations.inventory.transfers.show', $transfer->id)
            ->with('success', 'Transfer created successfully.');
    }

    public function show(string $transfer): Response
    {
        $model = $this->transferRepository->find($transfer);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Transfers/Show', [
            'transfer' => new InventoryTransferResource($model),
        ]);
    }

    public function edit(string $transfer): Response
    {
        $model = $this->transferRepository->find($transfer);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Transfers/Edit', [
            'transfer'  => new InventoryTransferResource($model),
            'items'     => $this->masterDataService->paginateItems([], 500)->items(),
            'locations' => $this->masterDataService->paginateLocations([], 500)->items(),
        ]);
    }

    public function update(UpdateTransferRequest $request, string $transfer): RedirectResponse
    {
        $this->transferRepository->update($transfer, $request->validated());

        return redirect()->route('operations.inventory.transfers.show', $transfer)
            ->with('success', 'Transfer updated successfully.');
    }

    public function destroy(string $transfer): RedirectResponse
    {
        $model = $this->transferRepository->find($transfer);
        $this->authorize('delete', $model);

        $this->transferRepository->delete($transfer);

        return redirect()->route('operations.inventory.transfers.index')
            ->with('success', 'Transfer deleted successfully.');
    }

    public function complete(CompleteTransferRequest $request, string $transfer): JsonResponse
    {
        $model = $this->transferRepository->find($transfer);
        $this->authorize('complete', $model);

        $updated = $this->transferService->complete($transfer, auth()->id());

        return response()->json([
            'message'  => 'Transfer completed successfully.',
            'transfer' => new InventoryTransferResource($updated),
        ]);
    }

    public function cancel(CancelTransferRequest $request, string $transfer): JsonResponse
    {
        $model = $this->transferRepository->find($transfer);
        $this->authorize('cancel', $model);

        $updated = $this->transferRepository->update($transfer, [
            'status'       => TransferStatusEnum::Cancelled->value,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message'  => 'Transfer cancelled.',
            'transfer' => new InventoryTransferResource($updated),
        ]);
    }
}
