<?php

namespace Modules\Operations\Housekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;
use Modules\Foundation\User\Models\User;
use Modules\Operations\Housekeeping\Models\CleaningTask;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class HousekeepingCheckoutTurnoverWorkspaceQuery
{
    private const WALL_CLOCK_UTC = "clock_timestamp() AT TIME ZONE 'UTC'";

    private const REVIEW_MARKER = 'HK_CHECKOUT_TURNOVER_EVIDENCE_REVIEW_REQUIRED';

    public function __construct(private readonly HousekeepingAttendantWorkloadQuery $workload) {}

    /**
     * @param array<string, mixed> $filters
     * @param array{room: bool, cleaning_task: bool, room_readiness: bool} $navigation
     * @return array<string, mixed>
     */
    public function forProperty(string $propertyId, array $filters, array $navigation, ?User $actor = null): array
    {
        $rows = DB::query()->fromSub($this->classifiedRows($propertyId), 'turnovers');

        if (($filters['state'] ?? null) !== null) {
            $rows->where('operational_state', $filters['state']);
        }

        if (($filters['business_date'] ?? null) !== null) {
            $rows->where('business_date', $filters['business_date']);
        }

        if (($filters['task_status'] ?? null) !== null) {
            $rows->where('task_status', $filters['task_status']);
        }

        if (($filters['search'] ?? null) !== null) {
            $search = '%' . trim((string) $filters['search']) . '%';
            $rows->where(function (Builder $query) use ($search): void {
                foreach ([
                    'room_number',
                    'reservation_number',
                    'handoff_id',
                    'intake_id',
                    'checkout_execution_id',
                    'cleaning_task_code',
                ] as $column) {
                    $query->orWhereRaw("COALESCE({$column}, '') ILIKE ?", [$search]);
                }
            });
        }

        $sort = (string) ($filters['sort'] ?? 'occurred_at');
        $direction = (string) ($filters['direction'] ?? 'desc');
        $perPage = (int) ($filters['per_page'] ?? 25);
        $page = (int) ($filters['page'] ?? 1);

        $paginator = $rows
            ->orderBy($sort, $direction)
            ->orderBy('handoff_id')
            ->paginate($perPage, ['*'], 'page', $page)
            ->withQueryString();

        $pageData = $paginator->toArray();
        $pageData['data'] = array_map(
            fn (object $row): array => $this->safeRow($row, $navigation),
            $paginator->items(),
        );

        $selected = null;
        if (($filters['selected'] ?? null) !== null) {
            $selectedRow = DB::query()
                ->fromSub($this->classifiedRows($propertyId), 'turnovers')
                ->where('handoff_id', $filters['selected'])
                ->first();

            $selected = $selectedRow === null
                ? null
                : $this->assignmentDetail($propertyId, $this->safeRow($selectedRow, $navigation), $actor);
        }

        return [
            'turnovers' => $pageData,
            'kpis' => $this->kpis($propertyId),
            'selected_turnover' => $selected,
        ];
    }

    /**
     * Build the only operational-state classifier used by rows, detail, filters, and KPIs.
     */
    private function classifiedRows(string $propertyId): Builder
    {
        $review = $this->reviewRequiredSql();
        $state = "CASE
            WHEN {$review} THEN 'review_required'
            WHEN intake.id IS NOT NULL AND handoff.delivery_status = 'DELIVERED' THEN 'completed'
            WHEN intake.id IS NOT NULL AND handoff.delivery_status <> 'DELIVERED' THEN 'delivery_confirmation_pending'
            WHEN handoff.delivery_status = 'CLAIMED'
                 AND handoff.claim_expires_at > " . self::WALL_CLOCK_UTC . " THEN 'active_claim'
            WHEN intake.id IS NULL AND (
                (handoff.delivery_status IN ('PENDING', 'FAILED') AND handoff.available_at <= " . self::WALL_CLOCK_UTC . ")
                OR (handoff.delivery_status = 'CLAIMED' AND handoff.claim_expires_at <= " . self::WALL_CLOCK_UTC . ")
            ) THEN 'ready'
            WHEN intake.id IS NULL AND handoff.delivery_status = 'FAILED'
                 AND handoff.available_at > " . self::WALL_CLOCK_UTC . " THEN 'retry_wait'
            ELSE 'scheduled'
        END";

        return DB::table('front_desk_checkout_housekeeping_handoffs as handoff')
            ->leftJoin('housekeeping_checkout_turnover_intakes as intake', function (JoinClause $join) use ($propertyId): void {
                $join->on('intake.front_desk_checkout_housekeeping_handoff_id', '=', 'handoff.id')
                    ->where('intake.property_id', '=', $propertyId);
            })
            ->leftJoin('front_desk_checkout_executions as execution', function (JoinClause $join) use ($propertyId): void {
                $join->on('execution.id', '=', 'handoff.checkout_execution_id')
                    ->where('execution.property_id', '=', $propertyId);
            })
            ->leftJoin('front_desk_stays as stay', function (JoinClause $join) use ($propertyId): void {
                $join->on('stay.id', '=', 'handoff.front_desk_stay_id')
                    ->where('stay.property_id', '=', $propertyId);
            })
            ->leftJoin('reservations as reservation', function (JoinClause $join) use ($propertyId): void {
                $join->on('reservation.id', '=', 'handoff.reservation_id')
                    ->where('reservation.property_id', '=', $propertyId);
            })
            ->leftJoin('property_business_dates as business_date', function (JoinClause $join) use ($propertyId): void {
                $join->on('business_date.id', '=', 'handoff.property_business_date_id')
                    ->where('business_date.property_id', '=', $propertyId);
            })
            ->leftJoin('rooms as room', function (JoinClause $join) use ($propertyId): void {
                $join->on('room.id', '=', DB::raw('COALESCE(intake.room_id, stay.current_room_id)'))
                    ->where('room.property_id', '=', $propertyId);
            })
            ->leftJoin('cleaning_tasks as task', function (JoinClause $join) use ($propertyId): void {
                $join->on('task.id', '=', 'intake.cleaning_task_id')
                    ->where('task.property_id', '=', $propertyId);
            })
            ->leftJoin('housekeeping_room_readiness_transitions as transition', function (JoinClause $join) use ($propertyId): void {
                $join->on('transition.id', '=', 'intake.room_readiness_transition_id')
                    ->where('transition.property_id', '=', $propertyId);
            })
            ->where('handoff.property_id', $propertyId)
            ->select([
                'handoff.id as handoff_id',
                'intake.id as intake_id',
                'room.id as room_id',
                'room.room_number',
                'room.floor as room_floor',
                'reservation.reservation_number',
                'handoff.checkout_execution_id',
                'handoff.business_date',
                'handoff.occurred_at',
                'handoff.available_at',
                'handoff.claimed_at',
                'handoff.claim_expires_at',
                'handoff.failed_at',
                'handoff.delivered_at',
                'handoff.attempts',
                'handoff.delivery_status as handoff_status',
                'handoff.last_error_code',
                'task.id as cleaning_task_id',
                'task.task_code as cleaning_task_code',
                'task.status as task_status',
                'task.priority as task_priority',
                'task.started_at as task_started_at',
                'transition.id as readiness_transition_id',
                'transition.transition_type as readiness_transition_type',
                'room.readiness_state',
                'room.cleanliness_status',
                'intake.consumer_identity',
                'intake.room_readiness_before',
                'intake.room_readiness_after',
                'intake.cleanliness_before',
                'intake.cleanliness_after',
                'intake.created_at as intake_committed_at',
                'execution.terminal_stay_status',
                'stay.status as current_stay_status',
            ])
            ->selectRaw("{$state} AS operational_state")
            ->selectRaw('COALESCE(handoff.delivered_at, intake.created_at) AS canonical_completion_at')
            ->selectRaw(
                'GREATEST(0, EXTRACT(EPOCH FROM (' . self::WALL_CLOCK_UTC . ' - COALESCE('
                . 'handoff.delivered_at, handoff.failed_at, handoff.claimed_at, intake.created_at, handoff.occurred_at'
                . '))))::bigint AS last_event_age_seconds'
            );
    }

    private function reviewRequiredSql(): string
    {
        return "(
            execution.id IS NULL
            OR stay.id IS NULL
            OR reservation.id IS NULL
            OR business_date.id IS NULL
            OR execution.front_desk_stay_id IS DISTINCT FROM handoff.front_desk_stay_id
            OR execution.reservation_id IS DISTINCT FROM handoff.reservation_id
            OR execution.property_business_date_id IS DISTINCT FROM handoff.property_business_date_id
            OR execution.business_date IS DISTINCT FROM handoff.business_date
            OR execution.terminal_stay_status IS DISTINCT FROM 'CHECKED_OUT'
            OR stay.reservation_id IS DISTINCT FROM handoff.reservation_id
            OR stay.status IS DISTINCT FROM 'CHECKED_OUT'
            OR business_date.business_date IS DISTINCT FROM handoff.business_date
            OR (handoff.delivery_status = 'DELIVERED' AND intake.id IS NULL)
            OR (handoff.delivery_status = 'PENDING' AND (
                handoff.attempts <> 0 OR handoff.claimed_at IS NOT NULL OR handoff.claim_expires_at IS NOT NULL
                OR handoff.delivered_at IS NOT NULL OR handoff.failed_at IS NOT NULL OR handoff.last_error_code IS NOT NULL
            ))
            OR (handoff.delivery_status = 'CLAIMED' AND (
                handoff.attempts < 1 OR handoff.claimed_at IS NULL OR handoff.claim_expires_at IS NULL
                OR handoff.delivered_at IS NOT NULL OR handoff.failed_at IS NOT NULL OR handoff.last_error_code IS NOT NULL
            ))
            OR (handoff.delivery_status = 'DELIVERED' AND (
                handoff.attempts < 1 OR handoff.delivered_at IS NULL OR handoff.failed_at IS NOT NULL
                OR handoff.last_error_code IS NOT NULL
            ))
            OR (handoff.delivery_status = 'FAILED' AND (
                handoff.attempts < 1 OR handoff.failed_at IS NULL OR handoff.last_error_code IS NULL
                OR handoff.available_at IS NULL OR handoff.delivered_at IS NOT NULL
            ))
            OR (intake.id IS NOT NULL AND (
                room.id IS NULL OR task.id IS NULL OR transition.id IS NULL
                OR intake.checkout_execution_id IS DISTINCT FROM handoff.checkout_execution_id
                OR intake.front_desk_stay_id IS DISTINCT FROM handoff.front_desk_stay_id
                OR intake.reservation_id IS DISTINCT FROM handoff.reservation_id
                OR intake.property_business_date_id IS DISTINCT FROM handoff.property_business_date_id
                OR intake.business_date IS DISTINCT FROM handoff.business_date
                OR intake.room_id IS DISTINCT FROM stay.current_room_id
                OR intake.handoff_source_hash IS DISTINCT FROM handoff.source_hash
                OR intake.checkout_execution_source_hash IS DISTINCT FROM execution.source_hash
                OR task.room_id IS DISTINCT FROM intake.room_id
                OR task.task_type IS DISTINCT FROM 'checkout_cleaning'
                OR transition.room_id IS DISTINCT FROM intake.room_id
                OR transition.transition_type IS DISTINCT FROM 'CHECKOUT_TURNOVER_INTAKE'
                OR transition.source_type IS DISTINCT FROM 'front_desk_checkout_housekeeping_handoff'
                OR transition.source_id IS DISTINCT FROM handoff.id
                OR transition.to_status IS DISTINCT FROM 'waiting_cleaning'
            ))
        )";
    }

    /**
     * @return array<string, int>
     */
    private function kpis(string $propertyId): array
    {
        $currentBusinessDate = DB::table('property_business_dates')
            ->where('property_id', $propertyId)
            ->where('status', 'Open')
            ->where('is_open', true)
            ->value('business_date');

        $kpis = DB::query()
            ->fromSub($this->classifiedRows($propertyId), 'turnovers')
            ->selectRaw("COUNT(*) FILTER (WHERE operational_state = 'ready') AS ready_now")
            ->selectRaw("COUNT(*) FILTER (WHERE operational_state = 'active_claim') AS active_claims")
            ->selectRaw("COUNT(*) FILTER (WHERE operational_state = 'retry_wait') AS retry_waiting")
            ->selectRaw("COUNT(*) FILTER (WHERE operational_state = 'delivery_confirmation_pending') AS delivery_confirmation_pending")
            ->selectRaw("COUNT(*) FILTER (WHERE operational_state = 'review_required') AS review_required")
            ->selectRaw(
                "COUNT(*) FILTER (WHERE operational_state = 'completed' AND canonical_completion_at::date = ?) AS completed_today",
                [$currentBusinessDate ?? '0001-01-01'],
            )
            ->first();

        return [
            'ready_now' => (int) ($kpis->ready_now ?? 0),
            'active_claims' => (int) ($kpis->active_claims ?? 0),
            'retry_waiting' => (int) ($kpis->retry_waiting ?? 0),
            'delivery_confirmation_pending' => (int) ($kpis->delivery_confirmation_pending ?? 0),
            'completed_today' => (int) ($kpis->completed_today ?? 0),
            'review_required' => (int) ($kpis->review_required ?? 0),
        ];
    }

    /**
     * @param array{room: bool, cleaning_task: bool, room_readiness: bool} $navigation
     * @return array<string, mixed>
     */
    private function safeRow(object $row, array $navigation): array
    {
        $state = (string) $row->operational_state;
        $errorCode = is_string($row->last_error_code)
            && preg_match('/^[A-Z0-9_]{1,100}$/', $row->last_error_code) === 1
                ? $row->last_error_code
                : null;

        return [
            'handoff_id' => (string) $row->handoff_id,
            'intake_id' => $this->nullableString($row->intake_id),
            'room_id' => $this->nullableString($row->room_id),
            'room_number' => $this->nullableString($row->room_number),
            'room_floor' => $this->nullableString($row->room_floor),
            'reservation_number' => $this->nullableString($row->reservation_number),
            'checkout_execution_id' => (string) $row->checkout_execution_id,
            'business_date' => (string) $row->business_date,
            'occurred_at' => $this->timestamp($row->occurred_at),
            'available_at' => $this->timestamp($row->available_at),
            'claimed_at' => $this->timestamp($row->claimed_at),
            'claim_expires_at' => $this->timestamp($row->claim_expires_at),
            'failed_at' => $this->timestamp($row->failed_at),
            'delivered_at' => $this->timestamp($row->delivered_at),
            'attempts' => (int) $row->attempts,
            'handoff_status' => (string) $row->handoff_status,
            'safe_last_error_code' => $errorCode,
            'operational_state' => $state,
            'review_marker' => $state === 'review_required' ? self::REVIEW_MARKER : null,
            'cleaning_task_id' => $this->nullableString($row->cleaning_task_id),
            'cleaning_task_code' => $this->nullableString($row->cleaning_task_code),
            'task_status' => $this->nullableString($row->task_status),
            'task_priority' => $this->nullableString($row->task_priority),
            'task_started_at' => $this->timestamp($row->task_started_at),
            'readiness_transition_id' => $this->nullableString($row->readiness_transition_id),
            'readiness_transition_type' => $this->nullableString($row->readiness_transition_type),
            'readiness_state' => $this->nullableString($row->readiness_state),
            'cleanliness_state' => $this->nullableString($row->cleanliness_status),
            'room_readiness_before' => $this->nullableString($row->room_readiness_before),
            'room_readiness_after' => $this->nullableString($row->room_readiness_after),
            'cleanliness_before' => $this->nullableString($row->cleanliness_before),
            'cleanliness_after' => $this->nullableString($row->cleanliness_after),
            'consumer_identity' => $this->nullableString($row->consumer_identity),
            'intake_committed_at' => $this->timestamp($row->intake_committed_at),
            'committed' => $row->intake_id !== null,
            'replayed_evidence' => null,
            'terminal_stay_evidence' => $row->terminal_stay_status === 'CHECKED_OUT'
                && $row->current_stay_status === 'CHECKED_OUT',
            'last_event_age_seconds' => max(0, (int) $row->last_event_age_seconds),
            'links' => [
                'room' => $navigation['room'] && $row->room_id !== null
                    ? '/operations/rooms/' . $row->room_id
                    : null,
                'cleaning_task' => $navigation['cleaning_task'] && $row->cleaning_task_id !== null
                    ? '/operations/cleaning-tasks/' . $row->cleaning_task_id
                    : null,
                'room_readiness' => $navigation['room_readiness'] && $row->room_id !== null
                    ? '/operations/room-readiness/' . $row->room_id
                    : null,
            ],
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        return $value === null ? null : (string) $value;
    }

    private function timestamp(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        return CarbonImmutable::parse((string) $value, 'UTC')->utc()->toISOString();
    }

    /**
     * @param array<string, mixed> $turnover
     * @return array<string, mixed>
     */
    private function assignmentDetail(string $propertyId, array $turnover, ?User $actor): array
    {
        $turnover['active_assignment'] = null;
        $turnover['assignment_history_summary'] = [
            'total' => 0,
            'reassigned' => 0,
            'completed' => 0,
            'cancelled' => 0,
        ];
        $turnover['assignment_actions'] = [
            'can_assign' => false,
            'can_reassign' => false,
            'assignment_blockers' => [],
        ];
        $turnover['eligible_attendants'] = [];
        $turnover['attendant_workload'] = [];

        if ($turnover['cleaning_task_id'] === null) {
            $turnover['assignment_actions']['assignment_blockers'] = ['TASK_NOT_AVAILABLE'];

            return $turnover;
        }

        $task = CleaningTask::withoutGlobalScopes()
            ->whereKey($turnover['cleaning_task_id'])
            ->where('property_id', $propertyId)
            ->whereNull('deleted_at')
            ->first();
        if (! $task) {
            $turnover['assignment_actions']['assignment_blockers'] = ['TASK_NOT_AVAILABLE'];

            return $turnover;
        }

        $assignments = DB::table('housekeeping_task_assignments as assignment')
            ->leftJoin('property_user as assignment_membership', function (JoinClause $join): void {
                $join->on('assignment_membership.user_id', '=', 'assignment.user_id')
                    ->on('assignment_membership.property_id', '=', 'assignment.property_id')
                    ->where('assignment_membership.status', '=', 'active');
            })
            ->leftJoin('users as users', function (JoinClause $join): void {
                $join->on('users.id', '=', 'assignment_membership.user_id')
                    ->on('users.department_id', '=', 'assignment.department_id')
                    ->where('users.is_active', '=', true)
                    ->whereNull('users.deleted_at');
            })
            ->leftJoin('departments as department', function (JoinClause $join): void {
                $join->on('department.id', '=', 'assignment.department_id')
                    ->on('department.property_id', '=', 'assignment.property_id')
                    ->where('department.is_active', '=', true)
                    ->whereNull('department.deleted_at');
            })
            ->where('assignment.property_id', $propertyId)
            ->where('assignment.cleaning_task_id', $task->id)
            ->whereNull('assignment.deleted_at')
            ->orderByDesc('assignment.assigned_at')
            ->orderByDesc('assignment.id')
            ->get([
                'assignment.id',
                'assignment.user_id',
                'assignment_membership.user_id as eligible_user_id',
                'users.name as user_name',
                'assignment.department_id',
                'department.name as department_name',
                'assignment.status',
                'assignment.assigned_at',
                'assignment.previous_assignment_id',
                'assignment.closed_at',
                'assignment.closure_reason',
            ]);
        $active = $assignments->where('status', 'active');
        $activeEvidenceValid = $active->count() === 1
            && $active->first()->eligible_user_id !== null
            && $active->first()->department_name !== null;
        if ($activeEvidenceValid) {
            $row = $active->first();
            $turnover['active_assignment'] = [
                'assignment_id' => (string) $row->id,
                'user_id' => (string) $row->user_id,
                'user_name' => $this->nullableString($row->user_name),
                'department_id' => $this->nullableString($row->department_id),
                'department_name' => $this->nullableString($row->department_name),
                'assignment_status' => (string) $row->status,
                'previous_assignment_id' => $this->nullableString($row->previous_assignment_id),
                'assigned_at' => $this->timestamp($row->assigned_at),
            ];
        }
        $turnover['assignment_history_summary'] = [
            'total' => $assignments->count(),
            'reassigned' => $assignments
                ->where('status', 'cancelled')
                ->where('closure_reason', 'reassigned')
                ->count(),
            'completed' => $assignments->where('status', 'completed')->count(),
            'cancelled' => $assignments->where('status', 'cancelled')->count(),
        ];

        $authorized = $actor !== null
            && $this->hasAssignmentPermission($actor)
            && $actor->can('assign', $task)
            && ($actor->isSuperAdmin() || DB::table('property_user')
                ->where('property_id', $propertyId)
                ->where('user_id', $actor->id)
                ->where('status', 'active')
                ->exists());
        if (! $authorized) {
            $turnover['assignment_actions']['assignment_blockers'] = ['ASSIGNMENT_PERMISSION_REQUIRED'];

            return $turnover;
        }
        if ($active->isNotEmpty() && ! $activeEvidenceValid) {
            $turnover['assignment_actions']['assignment_blockers'] = ['ASSIGNMENT_EVIDENCE_REVIEW_REQUIRED'];

            return $turnover;
        }
        if ($turnover['operational_state'] === 'review_required') {
            $turnover['assignment_actions']['assignment_blockers'] = ['EVIDENCE_REVIEW_REQUIRED'];

            return $turnover;
        }

        $status = $task->status instanceof \BackedEnum ? $task->status->value : (string) $task->status;
        $blockers = [];
        if ($status === 'pending' && $active->isEmpty()) {
            $turnover['assignment_actions']['can_assign'] = true;
        } elseif ($status === 'assigned' && $task->started_at === null && $active->count() === 1) {
            $turnover['assignment_actions']['can_reassign'] = true;
        } elseif (in_array($status, ['completed', 'cancelled'], true)) {
            $blockers[] = 'TASK_TERMINAL';
        } elseif ($task->started_at !== null || $status === 'in_progress') {
            $blockers[] = 'TASK_ALREADY_STARTED';
        } elseif ($status === 'assigned' && $active->count() !== 1) {
            $blockers[] = 'ACTIVE_ASSIGNMENT_MISSING';
        } else {
            $blockers[] = 'TASK_NOT_PENDING';
        }
        $turnover['assignment_actions']['assignment_blockers'] = $blockers;

        if ($turnover['assignment_actions']['can_assign'] || $turnover['assignment_actions']['can_reassign']) {
            $workload = $this->workload->forProperty($propertyId);
            $turnover['attendant_workload'] = $workload;
            $turnover['eligible_attendants'] = array_map(static fn (array $row): array => [
                'user_id' => $row['user_id'],
                'display_name' => $row['display_name'],
                'department_id' => $row['department_id'],
                'department_name' => $row['department_name'],
            ], $workload);
        }

        return $turnover;
    }

    private function hasAssignmentPermission(User $actor): bool
    {
        try {
            return $actor->hasPermissionTo(HousekeepingTaskDispatchAssignmentService::ASSIGN_PERMISSION);
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

}
