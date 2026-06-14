<?php

namespace Modules\Foundation\Department\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Department\Http\Requests\StorePositionRequest;
use Modules\Foundation\Department\Http\Requests\UpdatePositionRequest;
use Modules\Foundation\Department\Http\Resources\PositionResource;
use Modules\Foundation\Department\Services\PositionService;
use Modules\Foundation\Department\Models\Position;

class PositionController extends Controller
{
    public function __construct(
        private PositionService $positionService
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Position::class);

        $positions = $this->positionService->all();

        return Inertia::render('Foundation/Position/Index', [
            'positions' => PositionResource::collection($positions),
        ]);
    }

    public function store(StorePositionRequest $request): RedirectResponse
    {
        $this->authorize('create', Position::class);

        $this->positionService->create($request->validated());

        return redirect()->route('positions.index')
            ->with('success', 'Position created successfully.');
    }

    public function show(string $id): Response
    {
        $position = $this->positionService->find($id);
        $this->authorize('view', $position);

        return Inertia::render('Foundation/Position/Show', [
            'position' => new PositionResource($position),
        ]);
    }

    public function update(UpdatePositionRequest $request, string $id): RedirectResponse
    {
        $position = $this->positionService->find($id);
        $this->authorize('update', $position);

        $this->positionService->update($id, $request->validated());

        return redirect()->route('positions.index')
            ->with('success', 'Position updated successfully.');
    }

    public function destroy(string $id): RedirectResponse
    {
        $position = $this->positionService->find($id);
        $this->authorize('delete', $position);

        $this->positionService->delete($id);

        return redirect()->route('positions.index')
            ->with('success', 'Position deleted successfully.');
    }
}
