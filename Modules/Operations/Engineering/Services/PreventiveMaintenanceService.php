<?php

namespace Modules\Operations\Engineering\Services;

use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Validation\ValidationException;
use Modules\Operations\Engineering\Enums\PmStatusEnum;
use Modules\Operations\Engineering\Enums\PmTaskStatusEnum;
use Modules\Operations\Engineering\Events\PreventiveMaintenanceTaskGenerated;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;
use Modules\Operations\Engineering\Models\PreventiveMaintenanceTask;
use Modules\Operations\Engineering\Repositories\PreventiveMaintenanceRepository;
use Modules\Operations\Engineering\Repositories\PreventiveMaintenanceTaskRepository;

class PreventiveMaintenanceService
{
    public function __construct(
        private PreventiveMaintenanceRepository     $pmRepository,
        private PreventiveMaintenanceTaskRepository $taskRepository,
    ) {}

    public function paginate(?array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        return $this->pmRepository->paginate($filters, $perPage);
    }

    public function find(string $id): PreventiveMaintenance
    {
        return $this->pmRepository->find($id);
    }

    public function create(array $data): PreventiveMaintenance
    {
        return $this->pmRepository->create($data);
    }

    /**
     * Update PM program fields. Schedule fields (last_run_at, next_due_at) and
     * status are controlled by dedicated methods and are stripped here.
     */
    public function update(string $id, array $data): PreventiveMaintenance
    {
        unset($data['status'], $data['last_run_at'], $data['next_due_at']);

        return $this->pmRepository->update($id, $data);
    }

    public function delete(string $id): bool
    {
        return $this->pmRepository->delete($id);
    }

    public function activate(string $id): PreventiveMaintenance
    {
        return $this->transitionStatus($id, PmStatusEnum::Active);
    }

    public function deactivate(string $id): PreventiveMaintenance
    {
        return $this->transitionStatus($id, PmStatusEnum::Inactive);
    }

    public function pause(string $id): PreventiveMaintenance
    {
        return $this->transitionStatus($id, PmStatusEnum::Paused);
    }

    /**
     * Generate a task instance for a PM program.
     *
     * Creates a PreventiveMaintenanceTask with the given scheduled date (defaults
     * to now), updates the PM's next_due_at based on the program's frequency, and
     * fires PreventiveMaintenanceTaskGenerated.
     */
    public function generateTask(string $pmId, ?\Carbon\Carbon $scheduledDate = null): PreventiveMaintenanceTask
    {
        $pm            = $this->pmRepository->find($pmId);
        $scheduledDate = $scheduledDate ?? now();

        $task = $this->taskRepository->create([
            'property_id'               => $pm->property_id,
            'preventive_maintenance_id' => $pm->id,
            'scheduled_date'            => $scheduledDate,
            'status'                    => PmTaskStatusEnum::Scheduled->value,
        ]);

        // Advance the PM's next_due_at using the frequency interval.
        $intervalDays = $pm->frequency->intervalDays() ?? $pm->frequency_days;
        $nextDueAt    = $intervalDays ? $scheduledDate->copy()->addDays($intervalDays) : null;

        $pm->update(['next_due_at' => $nextDueAt]);

        event(new PreventiveMaintenanceTaskGenerated($task));

        return $task;
    }

    /**
     * Shared status-transition logic for activate / deactivate / pause.
     */
    private function transitionStatus(string $id, PmStatusEnum $new): PreventiveMaintenance
    {
        $pm   = $this->pmRepository->find($id);
        $from = $pm->status;

        if (! $from->canTransitionTo($new)) {
            throw ValidationException::withMessages([
                'status' => [
                    "Cannot transition PM program from {$from->label()} to {$new->label()}.",
                ],
            ]);
        }

        $pm->update(['status' => $new]);

        return $pm->fresh();
    }
}
