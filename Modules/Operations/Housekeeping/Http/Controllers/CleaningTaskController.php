<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Enums\TaskTypeEnum;
use Modules\Operations\Housekeeping\Http\Requests\ChangeCleaningTaskStatusRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreCleaningTaskRequest;
use Modules\Operations\Housekeeping\Http\Requests\StoreTaskAssignmentRequest;
use Modules\Operations\Housekeeping\Http\Requests\UpdateCleaningTaskRequest;
use Modules\Operations\Housekeeping\Http\Resources\CleaningTaskResource;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Shared\Services\CurrentPropertyService;

class CleaningTaskController extends Controller
{
    public function __construct(
        private CleaningTaskService $taskService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', CleaningTask::class);

        $filters = request()->only(['status', 'task_type', 'room_id', 'zone_id']);
        $tasks   = $this->taskService->paginate($filters);

        return Inertia::render('Operations/Housekeeping/Tasks/Index', [
            'tasks'      => CleaningTaskResource::collection($tasks),
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
            'statuses' => array_map(
                fn(TaskStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                TaskStatusEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', CleaningTask::class);

        return Inertia::render('Operations/Housekeeping/Tasks/Create', [
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreCleaningTaskRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $task = $this->taskService->create($data);

        return redirect()->route('operations.cleaning-tasks.show', $task->id)
            ->with('success', 'Cleaning task created successfully.');
    }

    public function show(string $task): Response
    {
        $model = $this->taskService->find($task);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Housekeeping/Tasks/Show', [
            'task' => new CleaningTaskResource($model),
        ]);
    }

    public function edit(string $task): Response
    {
        $model = $this->taskService->find($task);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Tasks/Edit', [
            'task'       => new CleaningTaskResource($model),
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateCleaningTaskRequest $request, string $task): RedirectResponse
    {
        $this->taskService->update($task, $request->validated());

        return redirect()->route('operations.cleaning-tasks.show', $task)
            ->with('success', 'Cleaning task updated successfully.');
    }

    public function destroy(string $task): RedirectResponse
    {
        $model = $this->taskService->find($task);
        $this->authorize('delete', $model);

        $this->taskService->delete($task);

        return redirect()->route('operations.cleaning-tasks.index')
            ->with('success', 'Cleaning task deleted successfully.');
    }

    public function changeStatus(ChangeCleaningTaskStatusRequest $request, string $task): JsonResponse
    {
        $data    = $request->validated();
        $status  = TaskStatusEnum::from($data['status']);
        $remarks = $data['remarks'] ?? null;

        $updated = $this->taskService->changeStatus($task, $status, $remarks);

        return response()->json([
            'message' => "Task status changed to {$status->label()}.",
            'task'    => new CleaningTaskResource($updated),
        ]);
    }

    public function assign(StoreTaskAssignmentRequest $request, string $task): RedirectResponse
    {
        $model = $this->taskService->find($task);
        $this->authorize('assign', $model);

        $this->taskService->assign($task, array_merge($request->validated(), [
            'assigned_by' => auth()->id(),
        ]));

        return redirect()->route('operations.cleaning-tasks.show', $task)
            ->with('success', 'Task assigned successfully.');
    }
}
