<?php

namespace Modules\Foundation\Approval\Repositories;

use Modules\Foundation\Approval\Models\ApprovalMatrixRule;
use Illuminate\Database\Eloquent\Collection;

class ApprovalMatrixRepository
{
    public function getRulesForDocument(string $module, string $documentType, ?string $departmentId, float $amount): Collection
    {
        return ApprovalMatrixRule::where('module', $module)
            ->where('document_type', $documentType)
            ->when($departmentId, function ($query) use ($departmentId) {
                $query->where(function ($q) use ($departmentId) {
                    $q->where('department_id', $departmentId)
                      ->orWhereNull('department_id');
                });
            })
            ->where(function ($query) use ($amount) {
                $query->where(function ($q) use ($amount) {
                    $q->where('min_amount', '<=', $amount)
                      ->orWhereNull('min_amount');
                })->where(function ($q) use ($amount) {
                    $q->where('max_amount', '>=', $amount)
                      ->orWhereNull('max_amount');
                });
            })
            ->get();
    }
}
