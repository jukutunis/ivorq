<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Modules\FunctionSpace\Enums\MaintenanceBlockTypeEnum;

class VenueMaintenanceBlock extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $fillable = [
        'venue_id',
        'maintenance_type',
        'start_datetime',
        'end_datetime',
        'reason',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'maintenance_type' => MaintenanceBlockTypeEnum::class,
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }
}
