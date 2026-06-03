<?php

namespace Modules\Foundation\Property\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Illuminate\Http\RedirectResponse;
use Modules\Foundation\Property\Http\Requests\StorePropertyRequest;
use Modules\Foundation\Property\Http\Requests\UpdatePropertyRequest;
use Modules\Foundation\Property\Http\Resources\PropertyResource;
use Modules\Foundation\Property\Services\PropertyService;

class PropertyController extends Controller
{
    public function __construct(
        private PropertyService $propertyService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', \Modules\Foundation\Property\Models\Property::class);

        $properties = $this->propertyService->paginate();

        return Inertia::render('Foundation/Property/Index', [
            'properties' => PropertyResource::collection($properties),
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', \Modules\Foundation\Property\Models\Property::class);

        return Inertia::render('Foundation/Property/Create');
    }

    public function store(StorePropertyRequest $request): RedirectResponse
    {
        $property = $this->propertyService->create($request->validated());

        return redirect()->route('properties.show', $property->id)
            ->with('success', 'Property created successfully.');
    }

    public function show(string $id): Response
    {
        $property = $this->propertyService->find($id);
        $this->authorize('view', $property);

        return Inertia::render('Foundation/Property/Show', [
            'property' => new PropertyResource($property),
        ]);
    }

    public function edit(string $id): Response
    {
        $property = $this->propertyService->find($id);
        $this->authorize('update', $property);

        return Inertia::render('Foundation/Property/Edit', [
            'property' => new PropertyResource($property),
        ]);
    }

    public function update(UpdatePropertyRequest $request, string $id): RedirectResponse
    {
        $property = $this->propertyService->update($id, $request->validated());

        return redirect()->route('properties.show', $property->id)
            ->with('success', 'Property updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $property = $this->propertyService->find($id);
        $this->authorize('delete', $property);

        $this->propertyService->delete($id);

        return redirect()->route('properties.index')
            ->with('success', 'Property deleted successfully.');
    }
}
