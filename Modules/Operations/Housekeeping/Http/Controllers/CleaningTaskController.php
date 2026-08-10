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
use Modules\Operations\Housekeeping\Http\Requests\UpdateCleaningTaskRequest;
use Modules\Operations\Housekeeping\Http\Resources\CleaningTaskResource;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Services\CleaningTaskService;
use Modules\Operations\Housekeeping\Repositories\CleaningTaskRepository;
use Shared\Services\CurrentPropertyService;
use DomainException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class CleaningTaskController extends Controller
{
    public function __construct(
        private CleaningTaskService $taskService,
        private CleaningTaskRepository $taskRepository,
    ) {}

    public function index(): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = request()->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('viewAny', CleaningTask::class);

        $filters = request()->only(['status', 'task_type', 'room_id', 'zone_id']);
        $tasks   = $this->taskRepository->paginate($filters);

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
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = request()->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $this->authorize('create', CleaningTask::class);

        return Inertia::render('Operations/Housekeeping/Tasks/Create', [
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreCleaningTaskRequest $request)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($request->has('property_id') && $request->input('property_id') !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $data = array_merge($request->validated(), [
            'property_id' => $resolvedPropertyId,
        ]);

        $task = $this->taskService->create($data);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'task' => new CleaningTaskResource($task->fresh())
            ], 201);
        }

        return redirect()->route('operations.cleaning-tasks.show', $task->id)
            ->with('success', 'Cleaning task created successfully.');
    }

    public function show(\Illuminate\Http\Request $request, string $task): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->taskRepository->find($task);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('view', $model);

        return Inertia::render('Operations/Housekeeping/Tasks/Show', [
            'task' => new CleaningTaskResource($model),
        ]);
    }

    public function edit(\Illuminate\Http\Request $request, string $task): Response
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->taskRepository->find($task);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('update', $model);

        return Inertia::render('Operations/Housekeeping/Tasks/Edit', [
            'task'       => new CleaningTaskResource($model),
            'task_types' => array_map(
                fn(TaskTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                TaskTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateCleaningTaskRequest $request, string $task)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        if ($request->has('property_id') && $request->input('property_id') !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $model = $this->taskRepository->find($task);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('update', $model);

        $this->taskRepository->update($task, $request->validated());

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cleaning task updated successfully.'
            ]);
        }

        return redirect()->route('operations.cleaning-tasks.show', $task)
            ->with('success', 'Cleaning task updated successfully.');
    }

    public function destroy(\Illuminate\Http\Request $request, string $task)
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->taskRepository->find($task);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('delete', $model);

        $this->taskRepository->delete($task);

        if ($request->wantsJson() || $request->ajax()) {
            return response()->json([
                'success' => true,
                'message' => 'Cleaning task deleted successfully.'
            ]);
        }

        return redirect()->route('operations.cleaning-tasks.index')
            ->with('success', 'Cleaning task deleted successfully.');
    }

    public function changeStatus(ChangeCleaningTaskStatusRequest $request, string $task): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context is missing, mismatched, or unauthorized.");
        }

        $model = $this->taskRepository->find($task);
        if ($model->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException("Property context mismatch.");
        }

        $this->authorize('changeStatus', $model);

        $data    = $request->validated();
        $status  = TaskStatusEnum::from($data['status']);
        $remarks = $data['remarks'] ?? null;

        try {
            $updated = $this->taskService->changeStatus($task, $status, $request->user(), $remarks);
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (Throwable) {
            return response()->json(['message' => 'HOUSEKEEPING_LIFECYCLE_ACTION_FAILED'], 500);
        }

        return response()->json([
            'message' => "Task status changed to {$status->label()}.",
            'task'    => new CleaningTaskResource($updated->fresh(['room', 'assignments.user'])),
        ]);
    }

}
