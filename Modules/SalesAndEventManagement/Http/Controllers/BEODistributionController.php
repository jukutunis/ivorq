<?php

namespace Modules\SalesAndEventManagement\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Modules\SalesAndEventManagement\Models\BEODistribution;
use Modules\SalesAndEventManagement\Models\BEOAcknowledgement;
use Modules\SalesAndEventManagement\Policies\BEODistributionPolicy;
use Modules\SalesAndEventManagement\Services\BEODistributionService;
use Modules\SalesAndEventManagement\Services\AcknowledgementEngine;
use Modules\SalesAndEventManagement\Enums\DistributionSeverityEnum;
use Modules\SalesAndEventManagement\Exceptions\DistributionStateException;
use Shared\Services\CurrentPropertyService;

/**
 * BEODistributionController
 *
 * Thin HTTP boundary. All business logic lives in BEODistributionService
 * and DistributionStateMachine. This controller is responsible only for:
 *   - Property isolation (header + resolved context cross-check)
 *   - Policy authorization
 *   - Request validation
 *   - Delegating to service
 *   - JSON response shaping
 *
 * Pattern mirrors ShiftLogController.
 */
class BEODistributionController extends Controller
{
    public function __construct(
        protected BEODistributionService $service,
        protected AcknowledgementEngine $ackEngine,
    ) {
        Gate::policy(BEODistribution::class, BEODistributionPolicy::class);
        Gate::policy(BEOAcknowledgement::class, BEODistributionPolicy::class);
    }

    /**
     * POST /api/v1/sales-events/beo-distributions
     *
     * Create and distribute a BEO to departments in one action.
     * The service creates a DRAFT then immediately calls distributeBEO,
     * which the state machine guards against non-DRAFT sources.
     */
    public function distribute(Request $request): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Property context is missing, mismatched, or unauthorized.'
            );
        }

        $request->validate([
            'beo_issue_log_id' => ['required', 'string'],
            'severity'         => ['required', 'string', 'in:' . implode(',', array_column(DistributionSeverityEnum::cases(), 'value'))],
            'department_ids'   => ['required', 'array', 'min:1'],
            'department_ids.*' => ['required', 'string'],
        ]);

        $distribution = $this->service->createDistribution(
            $request->input('beo_issue_log_id'),
            DistributionSeverityEnum::from($request->input('severity'))
        );

        // Authorize against the newly created DRAFT (property already verified above)
        $this->authorize('distribute', $distribution);

        try {
            $distribution = $this->service->distributeBEO(
                $distribution->id,
                $request->user()->id,
                $request->input('department_ids')
            );
        } catch (DistributionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'      => true,
            'distribution' => $distribution->load('acknowledgements'),
        ], 201);
    }

    /**
     * POST /api/v1/sales-events/beo-distributions/{distribution}/cancel
     */
    public function cancel(Request $request, BEODistribution $distribution): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Property context is missing, mismatched, or unauthorized.'
            );
        }

        if ($distribution->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Property context mismatch.');
        }

        $this->authorize('cancel', $distribution);

        try {
            $cancelled = $this->service->cancelDistribution(
                $distribution->id,
                $request->user()->id
            );
        } catch (DistributionStateException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'      => true,
            'distribution' => $cancelled,
        ]);
    }

    /**
     * POST /api/v1/sales-events/beo-acknowledgements/{acknowledgement}/acknowledge
     */
    public function acknowledge(Request $request, BEOAcknowledgement $acknowledgement): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Property context is missing, mismatched, or unauthorized.'
            );
        }

        if ($acknowledgement->distribution->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Property context mismatch.');
        }

        $this->authorize('acknowledge', $acknowledgement);

        try {
            $ack = $this->ackEngine->acknowledge($acknowledgement->id, $request->user()->id);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'         => true,
            'acknowledgement' => $ack,
        ]);
    }

    /**
     * POST /api/v1/sales-events/beo-acknowledgements/{acknowledgement}/reject
     */
    public function reject(Request $request, BEOAcknowledgement $acknowledgement): JsonResponse
    {
        $resolvedPropertyId = app(CurrentPropertyService::class)->resolveOrFail();
        setPermissionsTeamId($resolvedPropertyId);

        $requestPropertyId = $request->header('X-Property-ID');
        if (empty($requestPropertyId) || $requestPropertyId !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException(
                'Property context is missing, mismatched, or unauthorized.'
            );
        }

        if ($acknowledgement->distribution->property_id !== $resolvedPropertyId) {
            throw new \Illuminate\Auth\Access\AuthorizationException('Property context mismatch.');
        }

        $this->authorize('reject', $acknowledgement);

        $request->validate([
            'reason' => ['required', 'string', 'min:1'],
        ]);

        try {
            $ack = $this->ackEngine->reject(
                $acknowledgement->id,
                $request->user()->id,
                $request->input('reason')
            );
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'success'         => true,
            'acknowledgement' => $ack,
        ]);
    }
}
