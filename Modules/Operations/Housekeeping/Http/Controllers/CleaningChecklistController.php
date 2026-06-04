<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Http\Requests\ReorderChecklistItemsRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreChecklistItemRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreCleaningChecklistRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateChecklistItemRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateCleaningChecklistRequest;
use Modules\Operations\Housekeeping\Http\Resources\ChecklistItemResource;
use Modules\Operations\Housekeeping\Http\Resources\CleaningChecklistResource;
use Modules\Operations\Housekeeping\Models\CleaningChecklist;
use Modules\Operations\Housekeeping\Services\ChecklistService;
use Shared\Services\CurrentPropertyService;

class CleaningChecklistController extends Controller
{
    public function __construct(
        private ChecklistService $checklistService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', CleaningChecklist::class);

        $checklists = $this->checklistService->paginate();

        return Inertia::render('Operations/Housekeeping/Checklists/Index', [
            'checklists' => CleaningChecklistResource::collection($checklists),
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CleaningChecklist::class);

        return Inertia::render('Operations/Housekeeping/Checklists/Create', [
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreCleaningChecklistRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $checklist = $this->checklistService->create($data);

        return redirect()->route('operations.checklists.show', $checklist->id)
            ->with('success', 'Checklist created successfully.');
    }

    public function show(string $checklist): Response
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Housekeeping/Checklists/Show', [
            'checklist' => new CleaningChecklistResource($model->loadCount('items')),
        ]);
    }

    public function edit(string $checklist): Response
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Checklists/Edit', [
            'checklist'  => new CleaningChecklistResource($model),
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateCleaningChecklistRequest $request, string $checklist): RedirectResponse
    {
        $this->checklistService->update($checklist, $request->validated());

        return redirect()->route('operations.checklists.show', $checklist)
            ->with('success', 'Checklist updated successfully.');
    }

    public function destroy(string $checklist): RedirectResponse
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('delete', $model);

        $this->checklistService->delete($checklist);

        return redirect()->route('operations.checklists.index')
            ->with('success', 'Checklist deleted successfully.');
    }

    public function addItem(StoreChecklistItemRequest $request, string $checklist): RedirectResponse
    {
        $this->checklistService->addItem($checklist, $request->validated());

        return redirect()->route('operations.checklists.show', $checklist)
            ->with('success', 'Item added successfully.');
    }

    public function updateItem(UpdateChecklistItemRequest $request, string $checklist, string $item): RedirectResponse
    {
        $this->checklistService->updateItem($item, $request->validated());

        return redirect()->route('operations.checklists.show', $checklist)
            ->with('success', 'Item updated successfully.');
    }

    public function deleteItem(string $checklist, string $item): RedirectResponse
    {
        $model = $this->checklistService->find($checklist);
        $this->authorize('update', $model);

        $this->checklistService->deleteItem($item);

        return redirect()->route('operations.checklists.show', $checklist)
            ->with('success', 'Item removed successfully.');
    }

    public function reorderItems(ReorderChecklistItemsRequest $request, string $checklist): JsonResponse
    {
        $this->checklistService->reorderItems($request->validated()['items']);

        return response()->json(['message' => 'Items reordered successfully.']);
    }
}
