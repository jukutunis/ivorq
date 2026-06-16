<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Department\Models\Department;
use Shared\Traits\HasAuditColumns;

class StaffingSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'staffing_sections';

    protected $fillable = [
        'operational_package_id',
        'role_name',
        'department_id',
        'headcount',
        'shift_duration_hours',
    ];

    public function operationalPackage(): BelongsTo
    {
        return $this->belongsTo(OperationalPackage::class, 'operational_package_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'department_id');
    }
}
