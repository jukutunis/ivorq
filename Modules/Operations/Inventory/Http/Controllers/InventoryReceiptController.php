<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\ReceiptStatusEnum;
use Modules\Operations\Inventory\Http\Requests\CancelReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\PostReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\StoreReceiptRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateReceiptRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryReceiptResource;
use Modules\Operations\Inventory\Models\InventoryReceipt;
use Modules\Operations\Inventory\Repositories\InventoryReceiptRepository;
use Modules\Operations\Inventory\Services\ReceiptService;
use Shared\Services\CurrentPropertyService;

class InventoryReceiptController extends Controller
{
    public function __construct(
        private ReceiptService $receiptService,
        private InventoryReceiptRepository $receiptRepository,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryReceipt::class);

        $filters  = request()->only(['status', 'supplier_name']);
        $receipts = $this->receiptRepository->paginate($filters);

        return Inertia::render('Operations/Inventory/Receipts/Index', [
            'receipts' => InventoryReceiptResource::collection($receipts),
            'statuses' => array_map(
                fn(ReceiptStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                ReceiptStatusEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryReceipt::class);

        return Inertia::render('Operations/Inventory/Receipts/Create');
    }

    public function store(StoreReceiptRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $receipt = $this->receiptService->create($data);

        return redirect()->route('operations.inventory.receipts.show', $receipt->id)
            ->with('success', 'Receipt created successfully.');
    }

    public function show(string $receipt): Response
    {
        $model = $this->receiptRepository->find($receipt);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Receipts/Show', [
            'receipt' => new InventoryReceiptResource($model),
        ]);
    }

    public function edit(string $receipt): Response
    {
        $model = $this->receiptRepository->find($receipt);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Receipts/Edit', [
            'receipt' => new InventoryReceiptResource($model),
        ]);
    }

    public function update(UpdateReceiptRequest $request, string $receipt): RedirectResponse
    {
        $this->receiptRepository->update($receipt, $request->validated());

        return redirect()->route('operations.inventory.receipts.show', $receipt)
            ->with('success', 'Receipt updated successfully.');
    }

    public function destroy(string $receipt): RedirectResponse
    {
        $model = $this->receiptRepository->find($receipt);
        $this->authorize('delete', $model);

        $this->receiptRepository->delete($receipt);

        return redirect()->route('operations.inventory.receipts.index')
            ->with('success', 'Receipt deleted successfully.');
    }

    public function post(PostReceiptRequest $request, string $receipt): JsonResponse
    {
        $model = $this->receiptRepository->find($receipt);
        $this->authorize('post', $model);

        $updated = $this->receiptService->post($receipt, auth()->id());

        return response()->json([
            'message' => 'Receipt posted successfully.',
            'receipt' => new InventoryReceiptResource($updated),
        ]);
    }

    public function cancel(CancelReceiptRequest $request, string $receipt): JsonResponse
    {
        $model = $this->receiptRepository->find($receipt);
        $this->authorize('cancel', $model);

        $updated = $this->receiptRepository->update($receipt, [
            'status'       => ReceiptStatusEnum::Cancelled->value,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Receipt cancelled.',
            'receipt' => new InventoryReceiptResource($updated),
        ]);
    }
}
