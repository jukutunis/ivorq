<?php

namespace Modules\Finance\Payables\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Modules\Finance\Payables\Http\Requests\StoreVendorInvoiceRequest;
use Modules\Finance\Payables\Http\Requests\UpdateVendorInvoiceRequest;
use Modules\Finance\Payables\Http\Resources\VendorInvoiceResource;
use Modules\Finance\Payables\Repositories\VendorInvoiceRepository;
use Modules\Finance\Payables\Services\VendorInvoiceService;
use Modules\Finance\Payables\Models\VendorInvoice;

class VendorInvoiceController extends Controller
{
    public function __construct(
        protected VendorInvoiceService $service,
        protected VendorInvoiceRepository $repository
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', VendorInvoice::class);

        $query = $this->repository->query()
            ->where('property_id', $request->user()->property_id)
            ->with(['vendor', 'lines'])
            ->latest();

        return response()->json([
            'data' => VendorInvoiceResource::collection($query->paginate())
        ]);
    }

    public function store(StoreVendorInvoiceRequest $request): JsonResponse
    {
        $this->authorize('create', VendorInvoice::class);

        $invoice = $this->service->create($request->validated());

        return response()->json([
            'message' => 'Vendor invoice created successfully',
            'data' => new VendorInvoiceResource($invoice->load('lines'))
        ], 201);
    }

    public function show(Request $request, string $id): JsonResponse
    {
        $invoice = $this->repository->findOrFail($id);
        $this->authorize('view', $invoice);

        return response()->json([
            'data' => new VendorInvoiceResource($invoice->load('lines'))
        ]);
    }

    public function update(UpdateVendorInvoiceRequest $request, string $id): JsonResponse
    {
        $invoice = $this->repository->findOrFail($id);
        $this->authorize('update', $invoice);

        $updatedInvoice = $this->service->update($invoice, $request->validated());

        return response()->json([
            'message' => 'Vendor invoice updated successfully',
            'data' => new VendorInvoiceResource($updatedInvoice)
        ]);
    }

    public function cancel(Request $request, string $id): JsonResponse
    {
        $invoice = $this->repository->findOrFail($id);
        $this->authorize('cancel', $invoice);

        $cancelledInvoice = $this->service->cancel($invoice);

        return response()->json([
            'message' => 'Vendor invoice cancelled successfully',
            'data' => new VendorInvoiceResource($cancelledInvoice->load('lines'))
        ]);
    }
}
