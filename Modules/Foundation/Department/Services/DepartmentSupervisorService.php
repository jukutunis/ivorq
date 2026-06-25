<?php

namespace Modules\Foundation\Department\Services;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Validation\ValidationException;
use Modules\Foundation\Department\Models\Department;
use Modules\Foundation\Department\Models\DepartmentSupervisor;
use Modules\Foundation\User\Models\User;
use Shared\Services\CurrentPropertyService;

class DepartmentSupervisorService
{
    public function assignSupervisor(string $departmentId, string $userId): DepartmentSupervisor
    {
        return DB::transaction(function () use ($departmentId, $userId) {
            $propertyId = app(CurrentPropertyService::class)->getPropertyId();
            if (empty($propertyId)) {
                throw ValidationException::withMessages(['property' => 'Property context could not be resolved.']);
            }

            // Validate department belongs to property
            $department = Department::where('id', $departmentId)
                ->where('property_id', $propertyId)
                ->first();
            if (!$department) {
                throw ValidationException::withMessages(['department' => 'Department not found in active property context.']);
            }

            // Validate target user exists and has access to property
            $targetUser = User::find($userId);
            if (!$targetUser || !$targetUser->properties()->where('properties.id', $propertyId)->exists()) {
                throw ValidationException::withMessages(['user' => 'Target user does not have access to this property.']);
            }

            // Validate caller
            $caller = auth()->user();
            if (!$caller) {
                throw new AuthorizationException('Unauthenticated.');
            }

            // Check policy
            if (!Gate::allows('manage', [DepartmentSupervisor::class, $targetUser])) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            // Check active duplicate invariant
            $existingActive = DepartmentSupervisor::where('department_id', $departmentId)
                ->where('user_id', $userId)
                ->where('is_active', true)
                ->exists();
            if ($existingActive) {
                throw ValidationException::withMessages(['is_active' => 'An active supervisor assignment already exists for this user and department.']);
            }

            try {
                return DepartmentSupervisor::create([
                    'department_id' => $departmentId,
                    'user_id'       => $userId,
                    'is_active'     => true,
                ]);
            } catch (\Illuminate\Database\QueryException $e) {
                // Map database duplicate-active collision to validation exception
                if ($e->getCode() == '23505' || str_contains($e->getMessage(), 'uq_dept_user_active')) {
                    throw ValidationException::withMessages(['is_active' => 'An active supervisor assignment already exists for this user and department.']);
                }
                throw $e;
            }
        });
    }

    public function deactivateSupervisor(string $assignmentId): DepartmentSupervisor
    {
        return DB::transaction(function () use ($assignmentId) {
            $assignment = DepartmentSupervisor::lockForUpdate()->find($assignmentId);
            if (!$assignment) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Assignment not found.');
            }

            $propertyId = app(CurrentPropertyService::class)->getPropertyId();
            if (empty($propertyId) || $assignment->department->property_id !== $propertyId) {
                throw ValidationException::withMessages(['property' => 'Property context mismatch.']);
            }

            // Validate caller
            $caller = auth()->user();
            if (!$caller) {
                throw new AuthorizationException('Unauthenticated.');
            }

            // Check policy
            if (!Gate::allows('manageAssignment', $assignment)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            if (!$assignment->is_active) {
                throw ValidationException::withMessages(['is_active' => 'Assignment is already deactivated.']);
            }

            $assignment->is_active = false;
            $assignment->save();

            return $assignment;
        });
    }

    public function reactivateSupervisor(string $assignmentId): DepartmentSupervisor
    {
        return DB::transaction(function () use ($assignmentId) {
            $assignment = DepartmentSupervisor::lockForUpdate()->find($assignmentId);
            if (!$assignment) {
                throw new \Illuminate\Database\Eloquent\ModelNotFoundException('Assignment not found.');
            }

            $propertyId = app(CurrentPropertyService::class)->getPropertyId();
            if (empty($propertyId) || $assignment->department->property_id !== $propertyId) {
                throw ValidationException::withMessages(['property' => 'Property context mismatch.']);
            }

            // Validate caller
            $caller = auth()->user();
            if (!$caller) {
                throw new AuthorizationException('Unauthenticated.');
            }

            // Check policy
            if (!Gate::allows('manageAssignment', $assignment)) {
                throw new AuthorizationException('This action is unauthorized.');
            }

            if ($assignment->is_active) {
                throw ValidationException::withMessages(['is_active' => 'Assignment is already active.']);
            }

            // Recheck active duplicate invariant
            $existingActive = DepartmentSupervisor::where('department_id', $assignment->department_id)
                ->where('user_id', $assignment->user_id)
                ->where('is_active', true)
                ->exists();
            if ($existingActive) {
                throw ValidationException::withMessages(['is_active' => 'An active supervisor assignment already exists for this user and department.']);
            }

            try {
                $assignment->is_active = true;
                $assignment->save();
                return $assignment;
            } catch (\Illuminate\Database\QueryException $e) {
                // Map database duplicate-active collision to validation exception
                if ($e->getCode() == '23505' || str_contains($e->getMessage(), 'uq_dept_user_active')) {
                    throw ValidationException::withMessages(['is_active' => 'An active supervisor assignment already exists for this user and department.']);
                }
                throw $e;
            }
        });
    }

    public function isActiveSupervisorOf(string $departmentId, string $userId): bool
    {
        $propertyId = app(CurrentPropertyService::class)->getPropertyId();
        if (empty($propertyId)) {
            return false;
        }

        return DepartmentSupervisor::where('department_id', $departmentId)
            ->where('user_id', $userId)
            ->where('is_active', true)
            ->whereHas('department', function ($query) use ($propertyId) {
                $query->where('property_id', $propertyId);
            })
            ->exists();
    }
}
