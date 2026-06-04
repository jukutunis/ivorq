<?php

namespace Modules\Operations\Zoning\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\Zoning\Enums\ZonePriorityEnum;
use Modules\Operations\Zoning\Enums\ZoneTypeEnum;
use Modules\Operations\Zoning\Http\Requests\StoreZoneTemplateRequest;
use Modules\Operations\Zoning\Http\Requests\UpdateZoneTemplateRequest;
use Modules\Operations\Zoning\Http\Resources\ZoneTemplateResource;
use Modules\Operations\Zoning\Models\ZoneTemplate;
use Modules\Operations\Zoning\Services\ZoneTemplateService;
use Shared\Services\CurrentPropertyService;

class ZoneTemplateController extends Controller
{
    public function __construct(
        private ZoneTemplateService $templateService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', ZoneTemplate::class);

        $templates = $this->templateService->paginate();

        return Inertia::render('Operations/ZoneTemplate/Index', [
            'templates' => ZoneTemplateResource::collection($templates),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', ZoneTemplate::class);

        return Inertia::render('Operations/ZoneTemplate/Create', [
            'zone_types' => array_map(
                fn(ZoneTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                ZoneTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(ZonePriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                ZonePriorityEnum::cases()
            ),
        ]);
    }

    public function store(StoreZoneTemplateRequest $request): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $template = $this->templateService->create($data);

        return redirect()->route('operations.zone-templates.show', $template->id)
            ->with('success', 'Zone template created successfully.');
    }

    public function show(string $template): Response
    {
        $model = $this->templateService->find($template);
        $this->authorize('view', $model);

        return Inertia::render('Operations/ZoneTemplate/Show', [
            'template' => new ZoneTemplateResource($model),
        ]);
    }

    public function edit(string $template): Response
    {
        $model = $this->templateService->find($template);
        $this->authorize('update', $model);

        return Inertia::render('Operations/ZoneTemplate/Edit', [
            'template'   => new ZoneTemplateResource($model),
            'zone_types' => array_map(
                fn(ZoneTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                ZoneTypeEnum::cases()
            ),
            'priorities' => array_map(
                fn(ZonePriorityEnum $p) => ['value' => $p->value, 'label' => $p->label()],
                ZonePriorityEnum::cases()
            ),
        ]);
    }

    public function update(UpdateZoneTemplateRequest $request, string $template): RedirectResponse
    {
        $this->templateService->update($template, $request->validated());

        return redirect()->route('operations.zone-templates.show', $template)
            ->with('success', 'Zone template updated successfully.');
    }

    public function destroy(string $template): RedirectResponse
    {
        $model = $this->templateService->find($template);
        $this->authorize('delete', $model);

        $this->templateService->delete($template);

        return redirect()->route('operations.zone-templates.index')
            ->with('success', 'Zone template deleted successfully.');
    }
}
