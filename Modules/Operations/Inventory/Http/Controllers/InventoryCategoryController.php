<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Http\Requests\StoreCategoryRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateCategoryRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryCategoryResource;
use Modules\Operations\Inventory\Models\InventoryCategory;
use Modules\Operations\Inventory\Services\InventoryMasterDataService;
use Shared\Services\CurrentPropertyService;

class InventoryCategoryController extends Controller
{
    public function __construct(
        private InventoryMasterDataService $masterDataService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryCategory::class);

        $filters    = request()->only(['name', 'is_active']);
        $categories = $this->masterDataService->paginateCategories($filters);

        return Inertia::render('Operations/Inventory/Categories/Index', [
            'categories' => InventoryCategoryResource::collection($categories),
            'filters'    => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryCategory::class);

        return Inertia::render('Operations/Inventory/Categories/Create');
    }

    public function store(StoreCategoryRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $category = $this->masterDataService->createCategory($data);

        return redirect()->route('operations.inventory.categories.show', $category->id)
            ->with('success', 'Category created successfully.');
    }

    public function show(string $category): Response
    {
        $model = $this->masterDataService->findCategory($category);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Categories/Show', [
            'category' => new InventoryCategoryResource($model),
        ]);
    }

    public function edit(string $category): Response
    {
        $model = $this->masterDataService->findCategory($category);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Categories/Edit', [
            'category' => new InventoryCategoryResource($model),
        ]);
    }

    public function update(UpdateCategoryRequest $request, string $category): RedirectResponse
    {
        $this->masterDataService->updateCategory($category, $request->validated());

        return redirect()->route('operations.inventory.categories.show', $category)
            ->with('success', 'Category updated successfully.');
    }

    public function destroy(string $category): RedirectResponse
    {
        $model = $this->masterDataService->findCategory($category);
        $this->authorize('delete', $model);

        $this->masterDataService->deleteCategory($category);

        return redirect()->route('operations.inventory.categories.index')
            ->with('success', 'Category deleted successfully.');
    }
}
