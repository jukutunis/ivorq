<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Engineering\Enums\AssetRequestStatusEnum;
use Modules\Operations\Engineering\Enums\WorkOrderPriorityEnum;
use Modules\Operations\Engineering\Http\Requests\ApproveAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\FulfillAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\RejectAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\StoreAssetRequestRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateAssetRequestRequest;
use Modules\Operations\Engineering\Http\Resources\AssetRequestResource;
use Modules\Operations\Engineering\Models\AssetRequest;
use Modules\Operations\Engineering\Services\AssetRequestService;
use Shared\Services\CurrentPropertyService;

class AssetRequestController extends Controller
{
    public function __construct(
        private AssetRequestService $assetRequestService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', AssetRequest::class);

        $filters  = request()->only(['status', 'priority', 'work_order_id']);
        $requests = $this->assetRequestService->paginate($filters);

        return Inertia::render('Operations/Engineering/AssetRequests/Index', [
            'asset_requests' => AssetRequestResource::collection($requests),
            'statuses'       => array_map(
                fn(AssetRequestStatusEnum $s) => ['value' => $s->value, 'label' => $s->label()],
                AssetRequestStatusEnum::cases()
            ),
            'priorities' => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', AssetRequest::class);

        return Inertia::render('Operations/Engineering/AssetRequests/Create', [
            'priorities' => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
        ]);
    }

    public function store(StoreAssetRequestRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id'  => app(CurrentPropertyService::class)->getId(),
            'requester_id' => auth()->id(),
        ]);

        $assetRequest = $this->assetRequestService->create($data);

        return redirect()->route('operations.asset-requests.show', $assetRequest->id)
            ->with('success', 'Asset request submitted successfully.');
    }

    public function show(string $req): Response
    {
        $model = $this->assetRequestService->find($req);
        $this->authorize('view', $model);

        return Inertia::render('Operations/Engineering/AssetRequests/Show', [
            'asset_request' => new AssetRequestResource($model),
        ]);
    }

    public function edit(string $req): Response
    {
        $model = $this->assetRequestService->find($req);
        $this->authorize('update', $model);

        return Inertia::render('Operations/Engineering/AssetRequests/Edit', [
            'asset_request' => new AssetRequestResource($model),
            'priorities'    => array_map(
                fn(WorkOrderPriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                WorkOrderPriorityEnum::cases()
            ),
        ]);
    }

    public function update(UpdateAssetRequestRequest $request, string $req): RedirectResponse
    {
        $this->assetRequestService->update($req, $request->validated());

        return redirect()->route('operations.asset-requests.show', $req)
            ->with('success', 'Asset request updated successfully.');
    }

    public function destroy(string $req): RedirectResponse
    {
        $model = $this->assetRequestService->find($req);
        $this->authorize('delete', $model);

        $this->assetRequestService->delete($req);

        return redirect()->route('operations.asset-requests.index')
            ->with('success', 'Asset request deleted successfully.');
    }

    public function approve(ApproveAssetRequestRequest $request, string $req): RedirectResponse
    {
        $this->assetRequestService->approve($req);

        return redirect()->route('operations.asset-requests.show', $req)
            ->with('success', 'Asset request approved successfully.');
    }

    public function reject(RejectAssetRequestRequest $request, string $req): RedirectResponse
    {
        $this->assetRequestService->reject($req, $request->validated()['reason']);

        return redirect()->route('operations.asset-requests.show', $req)
            ->with('success', 'Asset request rejected.');
    }

    public function fulfill(FulfillAssetRequestRequest $request, string $req): RedirectResponse
    {
        $this->assetRequestService->fulfill($req);

        return redirect()->route('operations.asset-requests.show', $req)
            ->with('success', 'Asset request fulfilled.');
    }
}
