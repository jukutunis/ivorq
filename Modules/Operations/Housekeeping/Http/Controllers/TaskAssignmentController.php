<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use DomainException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Modules\Operations\Housekeeping\Http\Requests\StoreTaskAssignmentRequest;
use Modules\Operations\Housekeeping\Services\HousekeepingTaskDispatchAssignmentService;
use Modules\Operations\Housekeeping\ValueObjects\HousekeepingTaskAssignmentResult;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Throwable;

class TaskAssignmentController extends Controller
{
    public function __construct(
        private readonly HousekeepingTaskDispatchAssignmentService $assignmentService,
    ) {}

    public function assign(StoreTaskAssignmentRequest $request, string $task): JsonResponse|RedirectResponse
    {
        $data = $request->validated();

        try {
            $result = $this->assignmentService->assignOrReassign(
                $request->user(),
                $task,
                $data['user_id'],
                $data['department_id'],
                $data['idempotency_key'],
                $data['expected_active_assignment_id'],
            );

            return $this->committedResponse($request, $result);
        } catch (HttpException $exception) {
            return response()->json(['message' => $exception->getMessage()], $exception->getStatusCode());
        } catch (DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (Throwable) {
            return response()->json(['message' => 'HOUSEKEEPING_ASSIGNMENT_ACTION_FAILED'], 500);
        }
    }

    private function committedResponse(
        StoreTaskAssignmentRequest $request,
        HousekeepingTaskAssignmentResult $result,
    ): JsonResponse|RedirectResponse {
        if ($request->expectsJson() || $request->wantsJson()) {
            return response()->json($result->toArray(), $result->replayed ? 200 : 201);
        }

        return redirect()->back()->with('success', $result->replayed
            ? 'Assignment result recovered.'
            : 'Attendant assignment committed.');
    }
}
