<?php

namespace Modules\Operations\Purchasing\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Modules\Operations\Purchasing\Http\Requests\StoreVendorCategoryRequest;
use Modules\Operations\Purchasing\Http\Requests\UpdateVendorCategoryRequest;
use Modules\Operations\Purchasing\Http\Resources\VendorCategoryResource;
use Modules\Operations\Purchasing\Models\VendorCategory;
use Modules\Operations\Purchasing\Repositories\VendorCategoryRepository;

class VendorCategoryController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected VendorCategoryRepository $repository
    ) {
        $this->authorizeResource(VendorCategory::class, 'vendor_category');
    }

    public function index(Request $request): JsonResponse
    {
        $categories = $this->repository->paginate($request->all());

        return response()->json([
            'data' => VendorCategoryResource::collection($categories),
            'meta' => [
                'current_page' => $categories->currentPage(),
                'last_page' => $categories->lastPage(),
                'per_page' => $categories->perPage(),
                'total' => $categories->total(),
            ],
        ]);
    }

    public function store(StoreVendorCategoryRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['property_id'] = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();

        $category = $this->repository->create($data);

        return response()->json([
            'message' => 'Vendor category created successfully',
            'data' => new VendorCategoryResource($category),
        ], 201);
    }

    public function show(VendorCategory $vendorCategory): JsonResponse
    {
        return response()->json([
            'data' => new VendorCategoryResource($vendorCategory),
        ]);
    }

    public function update(UpdateVendorCategoryRequest $request, VendorCategory $vendorCategory): JsonResponse
    {
        $category = $this->repository->update($vendorCategory->id, $request->validated());

        return response()->json([
            'message' => 'Vendor category updated successfully',
            'data' => new VendorCategoryResource($category),
        ]);
    }

    public function destroy(VendorCategory $vendorCategory): JsonResponse
    {
        $this->repository->delete($vendorCategory->id);

        return response()->json([
            'message' => 'Vendor category deleted successfully',
        ]);
    }
}
