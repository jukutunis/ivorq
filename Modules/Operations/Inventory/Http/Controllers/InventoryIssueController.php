<?php

namespace Modules\Operations\Inventory\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Inventory\Enums\IssueStatusEnum;
use Modules\Operations\Inventory\Http\Requests\CancelIssueRequest;
use Modules\Operations\Inventory\Http\Requests\PostIssueRequest;
use Modules\Operations\Inventory\Http\Requests\StoreIssueRequest;
use Modules\Operations\Inventory\Http\Requests\UpdateIssueRequest;
use Modules\Operations\Inventory\Http\Resources\InventoryIssueResource;
use Modules\Operations\Inventory\Models\InventoryIssue;
use Modules\Operations\Inventory\Repositories\InventoryIssueRepository;
use Modules\Operations\Inventory\Services\IssueService;
use Shared\Services\CurrentPropertyService;

class InventoryIssueController extends Controller
{
    public function __construct(
        private IssueService $issueService,
        private InventoryIssueRepository $issueRepository,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', InventoryIssue::class);

        $filters = request()->only(['status', 'department_id']);
        $issues  = $this->issueRepository->paginate($filters);

        return Inertia::render('Operations/Inventory/Issues/Index', [
            'issues'   => InventoryIssueResource::collection($issues),
            'statuses' => array_map(
                fn(IssueStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                IssueStatusEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', InventoryIssue::class);

        return Inertia::render('Operations/Inventory/Issues/Create');
    }

    public function store(StoreIssueRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $issue = $this->issueService->create($data);

        return redirect()->route('operations.inventory.issues.show', $issue->id)
            ->with('success', 'Issue created successfully.');
    }

    public function show(string $issue): Response
    {
        $model = $this->issueRepository->find($issue);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Inventory/Issues/Show', [
            'issue' => new InventoryIssueResource($model),
        ]);
    }

    public function edit(string $issue): Response
    {
        $model = $this->issueRepository->find($issue);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Inventory/Issues/Edit', [
            'issue' => new InventoryIssueResource($model),
        ]);
    }

    public function update(UpdateIssueRequest $request, string $issue): RedirectResponse
    {
        $this->issueRepository->update($issue, $request->validated());

        return redirect()->route('operations.inventory.issues.show', $issue)
            ->with('success', 'Issue updated successfully.');
    }

    public function destroy(string $issue): RedirectResponse
    {
        $model = $this->issueRepository->find($issue);
        $this->authorize('delete', $model);

        $this->issueRepository->delete($issue);

        return redirect()->route('operations.inventory.issues.index')
            ->with('success', 'Issue deleted successfully.');
    }

    public function post(PostIssueRequest $request, string $issue): JsonResponse
    {
        $model = $this->issueRepository->find($issue);
        $this->authorize('post', $model);

        $updated = $this->issueService->post($issue, auth()->id());

        return response()->json([
            'message' => 'Issue posted successfully.',
            'issue'   => new InventoryIssueResource($updated),
        ]);
    }

    public function cancel(CancelIssueRequest $request, string $issue): JsonResponse
    {
        $model = $this->issueRepository->find($issue);
        $this->authorize('cancel', $model);

        $updated = $this->issueRepository->update($issue, [
            'status'       => IssueStatusEnum::Cancelled->value,
            'cancelled_by' => auth()->id(),
            'cancelled_at' => now(),
        ]);

        return response()->json([
            'message' => 'Issue cancelled.',
            'issue'   => new InventoryIssueResource($updated),
        ]);
    }
}
