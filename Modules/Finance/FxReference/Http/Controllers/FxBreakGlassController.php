<?php

namespace Modules\Finance\FxReference\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Finance\FxReference\Services\FxBreakGlassAccessService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class FxBreakGlassController extends Controller
{
    public function __construct(private readonly FxBreakGlassAccessService $breakGlassService) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');

        $isBroadAdmin = $this->breakGlassService->requiresBreakGlass($actor);
        $isActive = $this->breakGlassService->hasValidActivation($actor, $propertyId, $companyId);
        $metadata = $this->breakGlassService->activationMetadataFor($actor, $propertyId, $companyId);

        $propertyName = $this->resolvePropertyName($propertyId);

        return Inertia::render('Ivorq/Finance/FxBreakGlassAccess', [
            'isBroadAdmin' => $isBroadAdmin,
            'isActive' => $isActive,
            'activatedAt' => $metadata['activated_at'] ?? null,
            'expiresAt' => $metadata['expires_at'] ?? null,
            'reason' => $metadata['reason'] ?? null,
            'propertyName' => $propertyName,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');

        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:3', 'max:500'],
        ]);

        try {
            $this->breakGlassService->activate($actor, $validated['reason'], $propertyId, $companyId);

            return redirect()
                ->route('finance.fx-break-glass.index')
                ->with('success', 'FX break-glass access activated. This activation is temporary and auditable.');
        } catch (DomainException $exception) {
            return redirect()
                ->route('finance.fx-break-glass.index')
                ->with('error', $exception->getMessage());
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');

        try {
            $this->breakGlassService->deactivate($actor, $propertyId, $companyId);

            return redirect()
                ->route('finance.fx-break-glass.index')
                ->with('success', 'FX break-glass access deactivated.');
        } catch (DomainException $exception) {
            return redirect()
                ->route('finance.fx-break-glass.index')
                ->with('error', $exception->getMessage());
        }
    }

    private function resolveActor(Request $request): User
    {
        $user = $request->user();

        if (!$user instanceof User) {
            abort(401, 'Unauthenticated.');
        }

        return $user;
    }

    private function resolvePropertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();

        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function resolvePropertyName(string $propertyId): string
    {
        $property = \Modules\Foundation\Property\Models\Property::query()->find($propertyId);

        return $property?->name ?? 'Unknown Property';
    }
}
