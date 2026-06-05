<?php

namespace Modules\Operations\Engineering\Http\Controllers;

use App\Http\Controllers\Controller;
use Illuminate\Http\RedirectResponse;
use Modules\Operations\Engineering\Enums\TechnicianAssignmentStatusEnum;
use Modules\Operations\Engineering\Http\Requests\CompleteTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Http\Requests\StoreTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Http\Requests\UpdateTechnicianAssignmentRequest;
use Modules\Operations\Engineering\Repositories\TechnicianAssignmentRepository;
use Modules\Operations\Engineering\Services\TechnicianAssignmentService;
use Shared\Services\CurrentPropertyService;

class TechnicianAssignmentController extends Controller
{
    public function __construct(
        private TechnicianAssignmentService    $assignmentService,
        private TechnicianAssignmentRepository $assignmentRepository,
    ) {}

    public function store(StoreTechnicianAssignmentRequest $request, string $wo): RedirectResponse
    {
        $data = array_merge($request->validated(), [
            'work_order_id' => $wo,
            'property_id'   => app(CurrentPropertyService::class)->getId(),
            'assigned_at'   => now(),
            'status'        => TechnicianAssignmentStatusEnum::Active->value,
        ]);

        $this->assignmentService->create($data);

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Technician assigned successfully.');
    }

    public function update(UpdateTechnicianAssignmentRequest $request, string $wo, string $assignment): RedirectResponse
    {
        $this->assignmentService->update($assignment, $request->validated());

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Assignment updated successfully.');
    }

    public function destroy(string $wo, string $assignment): RedirectResponse
    {
        $model = $this->assignmentRepository->find($assignment);
        $this->authorize('update', $model);

        $this->assignmentService->cancel($assignment);

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Assignment cancelled.');
    }

    public function complete(CompleteTechnicianAssignmentRequest $request, string $wo, string $assignment): RedirectResponse
    {
        $this->assignmentService->complete($assignment, $request->validated());

        return redirect()->route('operations.work-orders.show', $wo)
            ->with('success', 'Assignment marked as completed.');
    }
}
