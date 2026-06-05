<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Engineering\Enums\EngineeringChecklistTypeEnum;
use Modules\Operations\Engineering\Http\Requests\ReorderEngineeringChecklistItemsRequest;
use Modules\Operations\Engineering\Http\Requests\StoreEngineeringChecklistItemRequest;
use Modules\Operations\Engineering\Http\Requests\StoreEngineeringChecklistRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateEngineeringChecklistItemRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateEngineeringChecklistRequest;
use Modules\Operations\Engineering\Http\Resources\EngineeringChecklistResource;
use Modules\Operations\Engineering\Models\EngineeringChecklist;
use Modules\Operations\Engineering\Services\EngineeringChecklistService;
use Shared\Services\CurrentPropertyService;

class EngineeringChecklistController extends Controller
{
    public function __construct(
        private EngineeringChecklistService $checklistService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', EngineeringChecklist::class);

        $filters    = request()->only(['checklist_type', 'is_active']);
        $checklists = $this->checklistService->paginate($filters);

        return Inertia::render('Operations/Engineering/Checklists/Index', [
            'checklists'      => EngineeringChecklistResource::collection($checklists),
            'checklist_types' => array_map(
                fn(EngineeringChecklistTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                EngineeringChecklistTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', EngineeringChecklist::class);

        return Inertia::render('Operations/Engineering/Checklists/Create', [
            'checklist_types' => array_map(
                fn(EngineeringChecklistTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                EngineeringChecklistTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreEngineeringChecklistRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $checklist = $this->checklistService->create($data);

        return redirect()->route('operations.engineering-checklists.show', $checklist->id)
            ->with('success', 'Engineering checklist created successfully.');
    }

    public function show(string $checklist): Response
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Engineering/Checklists/Show', [
            'checklist' => new EngineeringChecklistResource($model->load('items')->loadCount('items')),
        ]);
    }

    public function edit(string $checklist): Response
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Engineering/Checklists/Edit', [
            'checklist'       => new EngineeringChecklistResource($model),
            'checklist_types' => array_map(
                fn(EngineeringChecklistTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                EngineeringChecklistTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateEngineeringChecklistRequest $request, string $checklist): RedirectResponse
    {
        $this->checklistService->update($checklist, $request->validated());

        return redirect()->route('operations.engineering-checklists.show', $checklist)
            ->with('success', 'Checklist updated successfully.');
    }

    public function destroy(string $checklist): RedirectResponse
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('delete', $model);

        $this->checklistService->delete($checklist);

        return redirect()->route('operations.engineering-checklists.index')
            ->with('success', 'Checklist deleted successfully.');
    }

    public function addItem(StoreEngineeringChecklistItemRequest $request, string $checklist): RedirectResponse
    {
        $this->checklistService->addItem($checklist, $request->validated());

        return redirect()->route('operations.engineering-checklists.show', $checklist)
            ->with('success', 'Item added successfully.');
    }

    public function updateItem(UpdateEngineeringChecklistItemRequest $request, string $checklist, string $item): RedirectResponse
    {
        $this->checklistService->updateItem($item, $request->validated());

        return redirect()->route('operations.engineering-checklists.show', $checklist)
            ->with('success', 'Item updated successfully.');
    }

    public function deleteItem(string $checklist, string $item): RedirectResponse
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('update', $model);

        $this->checklistService->deleteItem($item);

        return redirect()->route('operations.engineering-checklists.show', $checklist)
            ->with('success', 'Item removed successfully.');
    }

    public function reorderItems(ReorderEngineeringChecklistItemsRequest $request, string $checklist): JsonResponse
    {
        $this->checklistService->reorderItems($request->validated()['items']);

        return response()->json(['message' => 'Items reordered successfully.']);
    }
}
