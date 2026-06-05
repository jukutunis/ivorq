<?php

namespace Modules\Operations\PMS\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Operations\PMS\Enums\GuestTypeEnum;
use Modules\Operations\PMS\Http\Requests\StoreGuestRequest;
use Modules\Operations\PMS\Http\Requests\UpdateGuestRequest;
use Modules\Operations\PMS\Http\Resources\GuestResource;
use Modules\Operations\PMS\Models\Guest;
use Modules\Operations\PMS\Services\GuestService;
use Shared\Services\CurrentPropertyService;

class GuestController extends Controller
{
    public function __construct(
        private GuestService $guestService,
    ) {}

    public function index(): Response
    {
        $this->authorize('viewAny', Guest::class);

        $filters = request()->only(['search', 'guest_type', 'vip_level']);
        $guests  = $this->guestService->paginate($filters);

        return Inertia::render('Operations/PMS/Guests/Index', [
            'guests'      => GuestResource::collection($guests),
            'guest_types' => array_map(
                fn (GuestTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                GuestTypeEnum::cases()
            ),
            'filters' => $filters,
        ]);
    }

    public function create(): Response
    {
        $this->authorize('create', Guest::class);

        return Inertia::render('Operations/PMS/Guests/Create', [
            'guest_types' => array_map(
                fn (GuestTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                GuestTypeEnum::cases()
            ),
        ]);
    }

    public function store(StoreGuestRequest $request): RedirectResponse
    {
        $propertyId = app(CurrentPropertyService::class)->getId();
        $seq        = Guest::where('property_id', $propertyId)->withTrashed()->count() + 1;

        $data = array_merge($request->validated(), [
            'property_id' => $propertyId,
            'guest_code'  => sprintf('GST-%05d', $seq),
        ]);

        $guest = $this->guestService->create($data);

        return redirect()->route('operations.pms.guests.show', $guest->id)
            ->with('success', 'Guest profile created successfully.');
    }

    public function show(string $guest): Response
    {
        $model = $this->guestService->find($guest);
        $this->authorize('view', $model);

        return Inertia::render('Operations/PMS/Guests/Show', [
            'guest' => new GuestResource($model),
        ]);
    }

    public function edit(string $guest): Response
    {
        $model = $this->guestService->find($guest);
        $this->authorize('update', $model);

        return Inertia::render('Operations/PMS/Guests/Edit', [
            'guest'       => new GuestResource($model),
            'guest_types' => array_map(
                fn (GuestTypeEnum $t) => ['value' => $t->value, 'label' => $t->label()],
                GuestTypeEnum::cases()
            ),
        ]);
    }

    public function update(UpdateGuestRequest $request, string $guest): RedirectResponse
    {
        $this->guestService->update($guest, $request->validated());

        return redirect()->route('operations.pms.guests.show', $guest)
            ->with('success', 'Guest profile updated successfully.');
    }

    public function destroy(string $guest): RedirectResponse
    {
        $model = $this->guestService->find($guest);
        $this->authorize('delete', $model);

        $this->guestService->delete($guest);

        return redirect()->route('operations.pms.guests.index')
            ->with('success', 'Guest profile deleted.');
    }
}
