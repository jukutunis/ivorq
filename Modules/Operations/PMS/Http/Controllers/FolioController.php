<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\PMS\Enums\FolioStatusEnum;
use Modules\Operations\PMS\Http\Requests\CloseFolioRequest;
use Modules\Operations\PMS\Http\Requests\PostFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioItemRequest;
use Modules\Operations\PMS\Http\Requests\VoidFolioRequest;
use Modules\Operations\PMS\Http\Resources\FolioItemResource;
use Modules\Operations\PMS\Http\Resources\FolioResource;
use Modules\Operations\PMS\Models\Folio;
use Modules\Operations\PMS\Services\FolioService;

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

        $existingOpenFolio = Folio::where('reservation_id', $reservation)
            ->where('status', FolioStatusEnum::Open)
            ->first();

        if ($existingOpenFolio) {
            return redirect()->route('operations.pms.folios.show', $existingOpenFolio->id)
                ->with('success', 'An open folio already exists for this reservation.');
        }

        // GLF-A: All aggregate-owned fields are server-resolved by the
        // controlled service. The controller passes only the reservation
        // identifier — no folio number, no currency, no property ID.
        $folio = $this->folioService->createForReservation($reservation);

        return redirect()->route('operations.pms.folios.show', $folio->id)
            ->with('success', 'Folio created successfully.');
    }

    public function postItem(PostFolioItemRequest $request, string $folio): JsonResponse
    {
        // GLF-A: Pass only validated business input.
        // Property is server-resolved in the aggregate service.
        $item = $this->folioService->postItem($folio, $request->validated());

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
