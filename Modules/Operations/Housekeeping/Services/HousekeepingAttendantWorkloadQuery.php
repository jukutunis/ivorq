<?php

namespace Modules\Operations\Housekeeping\Services;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Facades\DB;

class HousekeepingAttendantWorkloadQuery
{
    /** @return array<int, array<string, mixed>> */
    public function forProperty(string $propertyId): array
    {
        return DB::table('users as users')
            ->join('property_user as membership', function (JoinClause $join) use ($propertyId): void {
                $join->on('membership.user_id', '=', 'users.id')
                    ->where('membership.property_id', '=', $propertyId)
                    ->where('membership.status', '=', 'active');
            })
            ->join('departments as department', function (JoinClause $join) use ($propertyId): void {
                $join->on('department.id', '=', 'users.department_id')
                    ->where('department.property_id', '=', $propertyId)
                    ->where('department.is_active', '=', true)
                    ->whereNull('department.deleted_at');
            })
            ->leftJoin('housekeeping_task_assignments as assignment', function (JoinClause $join) use ($propertyId): void {
                $join->on('assignment.user_id', '=', 'users.id')
                    ->where('assignment.property_id', '=', $propertyId)
                    ->where('assignment.status', '=', 'active')
                    ->whereNull('assignment.deleted_at');
            })
            ->leftJoin('cleaning_tasks as task', function (JoinClause $join) use ($propertyId): void {
                $join->on('task.id', '=', 'assignment.cleaning_task_id')
                    ->where('task.property_id', '=', $propertyId)
                    ->whereIn('task.status', ['assigned', 'in_progress'])
                    ->whereNull('task.deleted_at');
            })
            ->where('users.is_active', true)
            ->whereNull('users.deleted_at')
            ->groupBy('users.id', 'users.name', 'department.id', 'department.name')
            ->orderBy('department.name')
            ->orderBy('users.name')
            ->orderBy('users.id')
            ->select([
                'users.id as user_id',
                'users.name as display_name',
                'department.id as department_id',
                'department.name as department_name',
            ])
            ->selectRaw('COUNT(task.id)::integer AS active_assignment_count')
            ->selectRaw("COUNT(task.id) FILTER (WHERE task.status = 'assigned')::integer AS assigned_not_started_count")
            ->selectRaw("COUNT(task.id) FILTER (WHERE task.status = 'in_progress')::integer AS in_progress_count")
            ->selectRaw("COUNT(task.id) FILTER (WHERE task.priority = 'rush')::integer AS rush_assignment_count")
            ->selectRaw('COALESCE(SUM(task.credits), 0)::numeric AS active_credits')
            ->selectRaw('MIN(assignment.assigned_at) AS oldest_active_assignment_at')
            ->get()
            ->map(static fn (object $row): array => [
                'user_id' => (string) $row->user_id,
                'display_name' => (string) $row->display_name,
                'department_id' => (string) $row->department_id,
                'department_name' => (string) $row->department_name,
                'active_assignment_count' => (int) $row->active_assignment_count,
                'assigned_not_started_count' => (int) $row->assigned_not_started_count,
                'in_progress_count' => (int) $row->in_progress_count,
                'rush_assignment_count' => (int) $row->rush_assignment_count,
                'active_credits' => (float) $row->active_credits,
                'oldest_active_assignment_at' => $row->oldest_active_assignment_at === null
                    ? null
                    : CarbonImmutable::parse((string) $row->oldest_active_assignment_at, 'UTC')->utc()->toISOString(),
            ])
            ->all();
    }
}
