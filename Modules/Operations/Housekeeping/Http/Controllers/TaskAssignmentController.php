<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Operations\Housekeeping\Models\TaskAssignment;
use Modules\Operations\Housekeeping\Services\TaskAssignmentService;

class TaskAssignmentController extends Controller
{
    public function __construct(
        private TaskAssignmentService $assignmentService,
    ) {}

    public function complete(string $task, string $assignment): RedirectResponse
    {
        $model = $this->assignmentService->find($assignment);
        $this->authorize('update', $model);

        $this->assignmentService->complete($assignment);

        return redirect()->route('operations.cleaning-tasks.show', $task)
            ->with('success', 'Assignment marked as completed.');
    }

    public function cancel(string $task, string $assignment): RedirectResponse
    {
        $model = $this->assignmentService->find($assignment);
        $this->authorize('update', $model);

        $this->assignmentService->cancel($assignment);

        return redirect()->route('operations.cleaning-tasks.show', $task)
            ->with('success', 'Assignment cancelled.');
    }
}
