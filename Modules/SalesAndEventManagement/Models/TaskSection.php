<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\Department\Models\Department;
use Modules\SalesAndEventManagement\Enums\TaskPriorityEnum;
use Shared\Traits\HasAuditColumns;

class TaskSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'task_sections';

    protected $fillable = [
        'operational_package_id',
        'task_name',
        'priority',
        'department_id',
        'due_offset_minutes',
    ];

    protected $casts = [
        'priority' => TaskPriorityEnum::class,
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
