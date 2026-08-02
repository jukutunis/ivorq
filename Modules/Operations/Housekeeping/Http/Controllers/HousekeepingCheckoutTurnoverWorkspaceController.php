<?php

namespace Modules\Operations\Housekeeping\Http\Controllers;

use App\Http\Controllers\Controller;
use Inertia\Inertia;
use Inertia\Response;
use Modules\Foundation\Property\Models\Property;
use Modules\Operations\Housekeeping\Enums\TaskStatusEnum;
use Modules\Operations\Housekeeping\Http\Requests\HousekeepingCheckoutTurnoverWorkspaceRequest;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Modules\Operations\Housekeeping\Models\Room;
use Modules\Operations\Housekeeping\Services\HousekeepingCheckoutTurnoverWorkspaceQuery;
use Modules\Operations\Housekeeping\Services\HousekeepingRoomReadinessProjectionService;
use Shared\Services\CurrentPropertyService;

class HousekeepingCheckoutTurnoverWorkspaceController extends Controller
{
    public function __construct(
        private readonly CurrentPropertyService $currentProperty,
        private readonly HousekeepingCheckoutTurnoverWorkspaceQuery $workspaceQuery,
    ) {}

    public function index(HousekeepingCheckoutTurnoverWorkspaceRequest $request): Response
    {
        $propertyId = $this->currentProperty->resolveOrFail();
        setPermissionsTeamId($propertyId);

        $this->authorize('viewAny', Room::class);

        $property = Property::query()
            ->whereKey($propertyId)
            ->where('is_active', true)
            ->firstOrFail();

        if (! $request->user()->isSuperAdmin()) {
            $hasActiveMembership = $property->users()
                ->where('users.id', $request->user()->id)
                ->wherePivot('status', 'active')
                ->exists();

            abort_unless($hasActiveMembership, 403);
        }

        $filters = $request->validated();
        $projection = $this->workspaceQuery->forProperty(
            $property->id,
            $filters,
            [
                'room' => true,
                'cleaning_task' => $request->user()->can('viewAny', CleaningTask::class),
                'room_readiness' => $request->user()->can(
                    HousekeepingRoomReadinessProjectionService::HOUSEKEEPING_VIEW_PERMISSION
                ),
            ],
        );

        if (($filters['selected'] ?? null) !== null && $projection['selected_turnover'] === null) {
            abort(404);
        }

        return Inertia::render('Operations/Housekeeping/CheckoutTurnovers/Index', [
            ...$projection,
            'filters' => [
                'state' => $filters['state'] ?? null,
                'search' => $filters['search'] ?? null,
                'business_date' => $filters['business_date'] ?? null,
                'task_status' => $filters['task_status'] ?? null,
                'sort' => $filters['sort'] ?? 'occurred_at',
                'direction' => $filters['direction'] ?? 'desc',
                'per_page' => (int) ($filters['per_page'] ?? 25),
                'selected' => $filters['selected'] ?? null,
            ],
            'options' => [
                'states' => HousekeepingCheckoutTurnoverWorkspaceRequest::STATES,
                'task_statuses' => array_map(
                    fn (TaskStatusEnum $status): array => [
                        'value' => $status->value,
                        'label' => $status->label(),
                    ],
                    TaskStatusEnum::cases(),
                ),
                'sorts' => HousekeepingCheckoutTurnoverWorkspaceRequest::SORTS,
            ],
        ]);
    }
}
