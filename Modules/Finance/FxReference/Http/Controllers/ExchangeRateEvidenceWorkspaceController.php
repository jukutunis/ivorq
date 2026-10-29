<?php

namespace Modules\Finance\FxReference\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Finance\FxReference\Models\ExchangeRateEvidence;
use Modules\Finance\FxReference\Services\ExchangeRateEvidenceService;
use Modules\Finance\FxReference\Services\FxBreakGlassAccessService;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;
use Throwable;

class ExchangeRateEvidenceWorkspaceController extends Controller
{
    private const ROUTE = 'finance.fx-rates.index';

    public function __construct(
        private readonly ExchangeRateEvidenceService $service,
        private readonly FxBreakGlassAccessService $breakGlass,
        private readonly SensitiveActionConfirmationService $confirmation,
    ) {}

    public function index(Request $request): Response
    {
        $user = $request->user();
        abort_unless($user && ($user->can(ExchangeRateEvidenceService::RECORD_PERMISSION) || $user->can(ExchangeRateEvidenceService::APPROVE_PERMISSION)), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);
        $this->guardBreakGlass($user, $propertyId, $request->session()->get('active_company_id'));
        $filters = $request->validate([
            'status' => ['nullable', 'string', 'in:RECORDED,APPROVED,REJECTED'],
            'currency' => ['nullable', 'string', 'max:3'],
            'effective_date' => ['nullable', 'date_format:Y-m-d'],
        ]);

        $evidence = ExchangeRateEvidence::query()
            ->with(['recorder:id,name', 'approver:id,name', 'rejector:id,name'])
            ->where('property_id', $propertyId)
            ->when($filters['status'] ?? null, fn ($query, $status) => $query->where('status', $status))
            ->when($filters['currency'] ?? null, fn ($query, $currency) => $query->where(function ($nested) use ($currency): void {
                $currency = strtoupper($currency);
                $nested->where('base_currency', $currency)->orWhere('quote_currency', $currency);
            }))
            ->when($filters['effective_date'] ?? null, fn ($query, $date) => $query->whereDate('effective_date', $date))
            ->orderByDesc('effective_date')
            ->latest('created_at')
            ->limit(100)
            ->get();

        return Inertia::render('Ivorq/Finance/ExchangeRateEvidenceWorkspace', [
            'filters' => $filters,
            'evidence' => $evidence->map(fn (ExchangeRateEvidence $item) => [
                'id' => $item->id,
                'base_currency' => $item->base_currency,
                'quote_currency' => $item->quote_currency,
                'rate' => (string) $item->rate,
                'quote_convention' => $item->quote_convention,
                'effective_date' => $item->effective_date?->toDateString(),
                'source_reference' => $item->source_reference,
                'status' => self::value($item->status),
                'recorded_by' => $item->recorder?->name,
                'recorded_at' => $item->recorded_at?->toIso8601String(),
                'approved_by' => $item->approver?->name,
                'approved_at' => $item->approved_at?->toIso8601String(),
                'rejected_by' => $item->rejector?->name,
                'rejected_at' => $item->rejected_at?->toIso8601String(),
                'rejection_reason' => $item->rejection_reason,
                'source_identity_hash' => $item->source_identity_hash,
            ])->values(),
            'permissions' => [
                'can_record' => $user->can(ExchangeRateEvidenceService::RECORD_PERMISSION),
                'can_approve' => $user->can(ExchangeRateEvidenceService::APPROVE_PERMISSION),
            ],
            'authority' => [
                'contract' => ExchangeRateEvidenceService::CONTRACT,
                'unrealized_fx' => 'DEFERRED_BY_ADR_061_062',
            ],
        ]);
    }

    public function record(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can(ExchangeRateEvidenceService::RECORD_PERMISSION), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);
        $this->guardBreakGlass($request->user(), $propertyId, $request->session()->get('active_company_id'));
        $validated = $request->validate([
            'base_currency' => ['required', 'string', 'size:3'],
            'quote_currency' => ['required', 'string', 'size:3', 'different:base_currency'],
            'rate' => ['required', 'string', 'regex:/^(0|[1-9][0-9]*)\.[0-9]{1,8}$/'],
            'quote_convention' => ['required', 'string', 'max:100'],
            'effective_date' => ['required', 'date_format:Y-m-d'],
            'source_reference' => ['required', 'string', 'max:255'],
        ]);

        return $this->action(fn () => $this->service->record(
            $propertyId,
            $validated['base_currency'],
            $validated['quote_currency'],
            $validated['rate'],
            $validated['quote_convention'],
            $validated['effective_date'],
            $validated['source_reference'],
            $request->user(),
        ), 'Exchange Rate evidence recorded.');
    }

    public function approve(Request $request, string $evidence): RedirectResponse
    {
        abort_unless($request->user()?->can(ExchangeRateEvidenceService::APPROVE_PERMISSION), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->scopedEvidence($evidence, $propertyId);
        if (! $this->confirmation->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before approving Exchange Rate evidence.');
        }

        return $this->action(fn () => $this->service->approve($evidence, $request->user()), 'Exchange Rate evidence approved.');
    }

    public function reject(Request $request, string $evidence): RedirectResponse
    {
        abort_unless($request->user()?->can(ExchangeRateEvidenceService::APPROVE_PERMISSION), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);
        $companyId = $request->session()->get('active_company_id');
        $this->guardBreakGlass($request->user(), $propertyId, $companyId);
        $this->scopedEvidence($evidence, $propertyId);
        if (! $this->confirmation->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before rejecting Exchange Rate evidence.');
        }
        $validated = $request->validate(['reason' => ['required', 'string', 'min:3', 'max:500']]);

        return $this->action(fn () => $this->service->reject($evidence, $validated['reason'], $request->user()), 'Exchange Rate evidence rejected.');
    }

    private function action(callable $action, string $success): RedirectResponse
    {
        try {
            $action();

            return redirect()->route(self::ROUTE)->with('success', $success);
        } catch (AuthorizationException $exception) {
            throw $exception;
        } catch (Throwable $exception) {
            return redirect()->route(self::ROUTE)->with('error', $exception->getMessage());
        }
    }

    private function scopedEvidence(string $id, string $propertyId): ExchangeRateEvidence
    {
        return ExchangeRateEvidence::where('property_id', $propertyId)->findOrFail($id);
    }

    private function propertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private function guardBreakGlass(User $user, string $propertyId, ?string $companyId): void
    {
        try {
            $this->breakGlass->requireOperationalFxAccess($user, $propertyId, $companyId);
        } catch (DomainException $exception) {
            abort(redirect()
                ->route('finance.fx-break-glass.index')
                ->with('error', $exception->getMessage()));
        }
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
