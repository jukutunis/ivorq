<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Modules\Operations\Purchasing\Http\Requests\StoreVendorRequest;
use Modules\Operations\Purchasing\Http\Requests\UpdateVendorRequest;
use Modules\Operations\Purchasing\Http\Resources\VendorResource;
use Modules\Operations\Purchasing\Models\Vendor;
use Modules\Operations\Purchasing\Repositories\VendorRepository;
use Modules\Operations\Purchasing\Services\VendorService;

class VendorController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected VendorRepository $repository,
        protected VendorService $service
    ) {
        $this->authorizeResource(Vendor::class, 'vendor');
    }

    public function index(Request $request): JsonResponse
    {
        $vendors = $this->repository->paginate($request->all());

        return response()->json([
            'data' => VendorResource::collection($vendors),
            'meta' => [
                'current_page' => $vendors->currentPage(),
                'last_page' => $vendors->lastPage(),
                'per_page' => $vendors->perPage(),
                'total' => $vendors->total(),
            ],
        ]);
    }

    public function store(StoreVendorRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['property_id'] = $request->user()->property_id;
        
        $contacts = $data['contacts'] ?? [];
        unset($data['contacts']);

        $vendor = $this->service->createVendorWithContacts($data, $contacts);

        return response()->json([
            'message' => 'Vendor created successfully',
            'data' => new VendorResource($vendor),
        ], 201);
    }

    public function show(Vendor $vendor): JsonResponse
    {
        $vendor->load(['category', 'contacts']);
        return response()->json([
            'data' => new VendorResource($vendor),
        ]);
    }

    public function update(UpdateVendorRequest $request, Vendor $vendor): JsonResponse
    {
        $data = $request->validated();
        
        $contacts = null;
        if (array_key_exists('contacts', $data)) {
            $contacts = $data['contacts'];
            unset($data['contacts']);
        }

        $updatedVendor = $this->service->updateVendorWithContacts($vendor->id, $data, $contacts);

        return response()->json([
            'message' => 'Vendor updated successfully',
            'data' => new VendorResource($updatedVendor),
        ]);
    }

    public function destroy(Vendor $vendor): JsonResponse
    {
        $this->repository->delete($vendor->id);

        return response()->json([
            'message' => 'Vendor deleted successfully',
        ]);
    }

    public function approve(Vendor $vendor): JsonResponse
    {
        $this->authorize('approve', $vendor);
        
        $updatedVendor = $this->service->toggleApproval($vendor->id);

        return response()->json([
            'message' => 'Vendor approval toggled successfully',
            'data' => new VendorResource($updatedVendor),
        ]);
    }
}
