<?php

namespace Modules\Operations\EngineeringWorkspace\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Modules\Operations\EngineeringWorkspace\Services\EngineeringDashboardService;
use Modules\Operations\EngineeringWorkspace\Services\GuestImpactBoardService;
use Modules\Operations\EngineeringWorkspace\Services\AssetHealthBoardService;
use Modules\Operations\EngineeringWorkspace\Services\ShiftHandoverService;
use Modules\Operations\EngineeringWorkspace\Services\MyAreaService;
use Modules\Operations\EngineeringWorkspace\Services\ApprovalQueueService;

class EngineeringWorkspaceController extends Controller
{
    public function dashboard(EngineeringDashboardService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getDashboard($request->user())]);
    }

    public function myTasks(EngineeringDashboardService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getMyTasks($request->user())]);
    }

    public function myAreas(MyAreaService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getMyAreas($request->user())]);
    }

    public function guestImpact(GuestImpactBoardService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getGuestImpactBoard($request->user())]);
    }

    public function assetHealth(AssetHealthBoardService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getAssetHealthBoard($request->user())]);
    }

    public function handover(ShiftHandoverService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getShiftHandover($request->user())]);
    }

    public function approvals(ApprovalQueueService $service, Request $request): JsonResponse
    {
        return response()->json(['data' => $service->getApprovalQueue($request->user())]);
    }
}
