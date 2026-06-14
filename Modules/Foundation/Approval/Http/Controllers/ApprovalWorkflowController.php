<?php

namespace Modules\Foundation\Approval\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\Approval\Http\Requests\StoreApprovalWorkflowRequest;
use Modules\Foundation\Approval\Http\Requests\UpdateApprovalWorkflowRequest;
use Modules\Foundation\Approval\Http\Resources\ApprovalWorkflowResource;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Repositories\ApprovalStepRepository;
use Modules\Foundation\Approval\Repositories\ApprovalWorkflowRepository;

class ApprovalWorkflowController extends Controller
{
    use AuthorizesRequests;

    public function __construct(
        protected ApprovalWorkflowRepository $repository,
        protected ApprovalStepRepository $stepRepository
    ) {
        $this->authorizeResource(ApprovalWorkflow::class, 'approval_workflow');
    }

    public function index(Request $request): JsonResponse
    {
        $workflows = $this->repository->paginate($request->all());

        return response()->json([
            'data' => ApprovalWorkflowResource::collection($workflows),
            'meta' => [
                'current_page' => $workflows->currentPage(),
                'last_page' => $workflows->lastPage(),
                'per_page' => $workflows->perPage(),
                'total' => $workflows->total(),
            ],
        ]);
    }

    public function store(StoreApprovalWorkflowRequest $request): JsonResponse
    {
        $data = $request->validated();
        $data['property_id'] = app(\Shared\Services\CurrentPropertyService::class)->getPropertyId();
        
        $steps = $data['steps'];
        unset($data['steps']);

        $workflow = DB::transaction(function () use ($data, $steps) {
            $workflow = $this->repository->create($data);
            
            foreach ($steps as $stepData) {
                $stepData['workflow_id'] = $workflow->id;
                $this->stepRepository->create($stepData);
            }
            
            return $workflow->fresh(['steps']);
        });

        return response()->json([
            'message' => 'Approval Workflow created successfully',
            'data' => new ApprovalWorkflowResource($workflow),
        ], 201);
    }

    public function show(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $approvalWorkflow->load(['steps']);
        return response()->json([
            'data' => new ApprovalWorkflowResource($approvalWorkflow),
        ]);
    }

    public function update(UpdateApprovalWorkflowRequest $request, ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $data = $request->validated();
        
        $steps = null;
        if (array_key_exists('steps', $data)) {
            $steps = $data['steps'];
            unset($data['steps']);
        }

        $workflow = DB::transaction(function () use ($approvalWorkflow, $data, $steps) {
            $workflow = $this->repository->update($approvalWorkflow->id, $data);
            
            if ($steps !== null) {
                // Delete existing steps
                $workflow->steps()->delete();
                
                foreach ($steps as $stepData) {
                    $stepData['workflow_id'] = $workflow->id;
                    $this->stepRepository->create($stepData);
                }
            }
            
            return $workflow->fresh(['steps']);
        });

        return response()->json([
            'message' => 'Approval Workflow updated successfully',
            'data' => new ApprovalWorkflowResource($workflow),
        ]);
    }

    public function destroy(ApprovalWorkflow $approvalWorkflow): JsonResponse
    {
        $this->repository->delete($approvalWorkflow->id);

        return response()->json([
            'message' => 'Approval Workflow deleted successfully',
        ]);
    }
}
