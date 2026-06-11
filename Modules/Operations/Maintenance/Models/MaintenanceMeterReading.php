<?php

namespace Modules\Operations\Maintenance\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Operations\AssetManagement\Models\Asset;
use Modules\Foundation\User\Models\User;

class MaintenanceMeterReading extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'maintenance_meter_readings';
    protected $guarded = ['id'];
    protected $casts = [
        'reading_date' => 'date',
        'reading_value' => 'decimal:2',
    ];

    public function plan(): BelongsTo
    {
        return $this->belongsTo(MaintenancePlan::class, 'maintenance_plan_id');
    }

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }

    public function readBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'read_by');
    }
}
