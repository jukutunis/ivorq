<?php

namespace Modules\Finance\GeneralLedger\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Finance\GeneralLedger\Models\FinancialPeriod;
use Modules\Finance\GeneralLedger\Services\FinancialPeriodInitializationService;
use Modules\Finance\GeneralLedger\Services\PostingPeriodGuard;
use Modules\Foundation\Authorization\Services\SensitiveActionConfirmationService;
use Modules\Foundation\Property\Services\CurrentBusinessDateService;
use Shared\Services\CurrentPropertyService;
use Throwable;

class FinancialPeriodControlWorkspaceController extends Controller
{
    private const PERMISSION = 'generalledger.period.manage';

    public function __construct(
        private readonly FinancialPeriodInitializationService $initialization,
        private readonly PostingPeriodGuard $postingPeriodGuard,
        private readonly CurrentBusinessDateService $currentBusinessDate,
        private readonly SensitiveActionConfirmationService $confirmation,
    ) {}

    public function index(Request $request): Response
    {
        abort_unless($request->user()?->can(self::PERMISSION), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);

        $businessDate = null;
        $businessDateError = null;
        try {
            $resolved = $this->currentBusinessDate->getActiveBusinessDate();
            $businessDate = [
                'id' => $resolved->id,
                'date' => $resolved->business_date->toDateString(),
                'status' => self::value($resolved->status),
            ];
        } catch (Throwable $exception) {
            $businessDateError = $exception->getMessage();
        }

        $postingReadiness = ['allowed' => false, 'reason' => null, 'period_id' => null];
        try {
            $result = $this->postingPeriodGuard->assertPostingAllowed();
            $postingReadiness = ['allowed' => true, 'reason' => null, 'period_id' => $result->financialPeriodId];
        } catch (Throwable $exception) {
            $postingReadiness['reason'] = $exception->getMessage();
        }

        return Inertia::render('Ivorq/Finance/FinancialPeriodControlWorkspace', [
            'periods' => FinancialPeriod::query()
                ->where('property_id', $propertyId)
                ->orderByDesc('period_year')
                ->orderByDesc('period_month')
                ->get()
                ->map(fn (FinancialPeriod $period) => [
                    'id' => $period->id,
                    'year' => $period->period_year,
                    'month' => $period->period_month,
                    'status' => self::value($period->status),
                    'opened_at' => $period->opened_at?->toIso8601String(),
                    'opened_by' => $period->opened_by,
                    'closed_at' => $period->closed_at?->toIso8601String(),
                    'closed_by' => $period->closed_by,
                ])->values(),
            'business_date' => $businessDate,
            'business_date_error' => $businessDateError,
            'posting_readiness' => $postingReadiness,
            'authority' => [
                'initialization' => 'FinancialPeriodInitializationService',
                'posting' => 'PostingPeriodGuard',
                'close_reopen' => 'NOT_ACTIVATED_NO_CANONICAL_SOURCE_AUTHORIZED_SERVICE',
                'auto_creation' => 'DISABLED',
            ],
        ]);
    }

    public function initialize(Request $request): RedirectResponse
    {
        abort_unless($request->user()?->can(self::PERMISSION), 403, 'Unauthorized.');
        $propertyId = $this->propertyId($request);
        $companyId = $request->session()->get('active_company_id');

        if (! $this->confirmation->hasValidConfirmation($request->user(), 'finance-approval', $companyId, $propertyId)) {
            return redirect()
                ->route('system.sensitive-action-confirmation.index', ['intent' => 'finance-approval'])
                ->with('error', 'Sensitive action confirmation is required before initializing a Financial Period.');
        }

        try {
            $this->initialization->initialize($request->user());

            return redirect()->route('finance.general-ledger.period-control')->with('success', 'Financial Period initialized.');
        } catch (Throwable $exception) {
            return redirect()->route('finance.general-ledger.period-control')->with('error', $exception->getMessage());
        }
    }

    private function propertyId(Request $request): string
    {
        $propertyId = $request->session()->get('active_property_id')
            ?? app(CurrentPropertyService::class)->resolveOrFail();
        app(CurrentPropertyService::class)->setPropertyId($propertyId);

        return $propertyId;
    }

    private static function value(mixed $value): ?string
    {
        return $value instanceof \BackedEnum ? (string) $value->value : ($value === null ? null : (string) $value);
    }
}
