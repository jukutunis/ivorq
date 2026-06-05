<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\PMS\Enums\RatePlanTypeEnum;
use Modules\Operations\PMS\Http\Requests\StoreRatePlanRequest;
use Modules\Operations\PMS\Http\Requests\UpdateRatePlanRequest;
use Modules\Operations\PMS\Http\Resources\RatePlanResource;
use Modules\Operations\PMS\Models\RatePlan;
use Modules\Operations\PMS\Services\RatePlanService;
use Shared\Services\CurrentPropertyService;

class RatePlanController extends Controller
{
    public function __construct(
        private RatePlanService $ratePlanService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', RatePlan::class);

        $filters   = request()->only(['plan_type', 'is_active']);
        $ratePlans = $this->ratePlanService->paginate($filters);

        return Inertia::render('Operations/PMS/RatePlans/Index', [
            'rate_plans' => RatePlanResource::collection($ratePlans),
            'plan_types' => array_map(
                fn (RatePlanTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RatePlanTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', RatePlan::class);

        return Inertia::render('Operations/PMS/RatePlans/Create', [
            'plan_types' => array_map(
                fn (RatePlanTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RatePlanTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreRatePlanRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $ratePlan = $this->ratePlanService->create($data);

        return redirect()->route('operations.pms.rate-plans.show', $ratePlan->id)
            ->with('success', 'Rate plan created successfully.');
    }

    public function show(string $rate_plan): Response
    {
        $model = $this->ratePlanService->find($rate_plan);
        $this->authorize('view', $model);

        return Inertia::render('Operations/PMS/RatePlans/Show', [
            'rate_plan' => new RatePlanResource($model),
        ]);
    }

    public function edit(string $rate_plan): Response
    {
        $model = $this->ratePlanService->find($rate_plan);
        $this->authorize('update', $model);

        return Inertia::render('Operations/PMS/RatePlans/Edit', [
            'rate_plan'  => new RatePlanResource($model),
            'plan_types' => array_map(
                fn (RatePlanTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                RatePlanTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateRatePlanRequest $request, string $rate_plan): RedirectResponse
    {
        $this->ratePlanService->update($rate_plan, $request->validated());

        return redirect()->route('operations.pms.rate-plans.show', $rate_plan)
            ->with('success', 'Rate plan updated successfully.');
    }

    public function destroy(string $rate_plan): RedirectResponse
    {
        $model = $this->ratePlanService->find($rate_plan);
        $this->authorize('delete', $model);

        $this->ratePlanService->delete($rate_plan);

        return redirect()->route('operations.pms.rate-plans.index')
            ->with('success', 'Rate plan deleted.');
    }
}
