<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\PMS\Enums\FolioItemTypeEnum;
use Modules\Operations\PMS\Http\Requests\CloseFolioRequest;
use Modules\Operations\PMS\Http\Requests\PostFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioRequest;
use Modules\Operations\PMS\Http\Resources\FolioItemResource;
use Modules\Operations\PMS\Http\Resources\FolioResource;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\FolioService;
use Shared\Services\CurrentPropertyService;

class FolioController extends Controller
{
    public function __construct(
        private FolioService $folioService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Folio::class);

        $folios = Folio::with(['reservation.primaryGuest', 'guest'])
            ->latest()
            ->paginate(15);

        return Inertia::render('Operations/PMS/Folios/Index', [
            'folios' => FolioResource::collection($folios),
        ]);
    }

    public function show(string $folio): Response
    {
        $model = $this->folioService->find($folio);
        $this->authorize('view', $model);

        return Inertia::render('Operations/PMS/Folios/Show', [
            'folio' => new FolioResource($model),
        ]);
    }

    public function store(string $reservation): RedirectResponse
    {
        $this->authorize('create', Folio::class);

        $propertyId  = app(CurrentPropertyService::class)->getId();
        $seq         = Folio::where('property_id', $propertyId)->withTrashed()->count() + 1;
        $folioNumber = sprintf('FOL-%05d', $seq);

        $folio = $this->folioService->createForReservation($reservation, [
            'folio_number' => $folioNumber,
            'currency'     => 'MYR',
        ]);

        return redirect()->route('operations.pms.folios.show', $folio->id)
            ->with('success', 'Folio created successfully.');
    }

    public function postItem(PostFolioItemRequest $request, string $folio): JsonResponse
    {
        $data = array_merge($request->validated(), [
            'property_id' => app(CurrentPropertyService::class)->getId(),
        ]);

        $item = $this->folioService->postItem($folio, $data);

        return response()->json([
            'message' => 'Item posted to folio.',
            'item'    => new FolioItemResource($item),
        ]);
    }

    public function voidItem(VoidFolioItemRequest $request, string $folio_item): JsonResponse
    {
        $item = $this->folioService->voidItem($folio_item);

        return response()->json([
            'message' => 'Folio item voided.',
            'item'    => new FolioItemResource($item),
        ]);
    }

    public function close(CloseFolioRequest $request, string $folio): JsonResponse
    {
        $updated = $this->folioService->close($folio);

        return response()->json([
            'message' => 'Folio closed.',
            'folio'   => new FolioResource($updated),
        ]);
    }

    public function void(VoidFolioRequest $request, string $folio): JsonResponse
    {
        $updated = $this->folioService->void($folio);

        return response()->json([
            'message' => 'Folio voided.',
            'folio'   => new FolioResource($updated),
        ]);
    }
}
