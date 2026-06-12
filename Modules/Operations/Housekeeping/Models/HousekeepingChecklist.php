<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class HousekeepingChecklist extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'housekeeping_checklists';

    protected $fillable = [
        'property_id',
        'name',
        'task_type',
        'total_points',
        'is_active',
    ];

    protected $casts = [
        'is_active' => 'boolean',
    ];
}