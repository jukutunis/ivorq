<?php

namespace Modules\Foundation\Authorization\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response as InertiaResponse;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class SensitiveActionConfirmationController extends Controller
{
    public function __construct(private readonly SensitiveActionConfirmationService $confirmationService) {}

    public function index(Request $request): InertiaResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $intent = $request->query('intent', '');

        if (!in_array($intent, SensitiveActionConfirmationService::REGISTERED_INTENTS, true)) {
            abort(400, 'Invalid sensitive action intent.');
        }

        $metadata = $this->confirmationService->confirmationMetadataFor($actor, $intent, $companyId, $propertyId);

        $propertyName = $this->resolvePropertyName($propertyId);

        return Inertia::render('Ivorq/System/SensitiveActionConfirmation', [
            'intent' => $intent,
            'intentLabel' => $this->intentLabel($intent),
            'propertyName' => $propertyName,
            'isConfirmed' => $metadata !== null,
            'confirmedAt' => $metadata['confirmed_at'] ?? null,
            'expiresAt' => $metadata['expires_at'] ?? null,
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');

        $validated = $request->validate([
            'intent' => ['required', 'string', 'in:' . implode(',', SensitiveActionConfirmationService::REGISTERED_INTENTS)],
            'password' => ['required', 'string'],
        ]);

        try {
            $this->confirmationService->confirm($actor, $validated['intent'], $validated['password'], $companyId, $propertyId);

            $postRoute = $this->postConfirmationRoute($validated['intent']);

            if ($postRoute !== null) {
                return redirect()
                    ->route($postRoute)
                    ->with('success', 'Sensitive action confirmed.');
            }

            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => $validated['intent']])
                ->with('success', 'Sensitive action confirmed.');
        } catch (DomainException $exception) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => $validated['intent']])
                ->with('error', $exception->getMessage());
        }
    }

    public function destroy(Request $request): RedirectResponse
    {
        $actor = $this->resolveActor($request);
        $propertyId = $this->resolvePropertyId($request);
        $companyId = $request->session()->get('active_company_id');

        $validated = $request->validate([
            'intent' => ['required', 'string', 'in:' . implode(',', SensitiveActionConfirmationService::REGISTERED_INTENTS)],
        ]);

        try {
            $this->confirmationService->invalidate($actor, $validated['intent'], $companyId, $propertyId);

            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => $validated['intent']])
                ->with('success', 'Sensitive action confirmation ended.');
        } catch (DomainException $exception) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => $validated['intent']])
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

    private function intentLabel(string $intent): string
    {
        $labels = [
            'finance-role-assignment' => 'Finance Role Assignment',
            'finance-approval' => 'Finance Approval',
            'fx-break-glass' => 'FX Break‑Glass Activation',
            'administrative-sensitive-action' => 'Administrative Sensitive Action',
            'cash-payment-execution' => 'Cash Payment Execution',
            'bank-payment-execution' => 'Bank Payment Execution',
            'banking-migration-account-identity-pilot-execution' => 'Banking Account Identity Pilot Execution',
            'engineering-room-availability-release' => 'Engineering Room Availability Release',
        ];

        return $labels[$intent] ?? $intent;
    }

    private function postConfirmationRoute(string $intent): ?string
    {
        $routes = [
            'finance-role-assignment' => 'finance.fx-operational-role-assignments.index',
            'finance-approval' => 'dashboard',
            'banking-migration-account-identity-pilot-execution' => 'finance.banking.migration.index',
        ];

        return $routes[$intent] ?? null;
    }
}
