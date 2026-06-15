<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Modules\SalesAndEventManagement\Enums\EventStatusEnum;
use Modules\SalesAndEventManagement\Enums\EventTypeEnum;

class Event extends Model
{
    use HasUlids;

    protected $fillable = [
        'opportunity_id',
        'event_name',
        'status',
        'event_type',
        'start_datetime',
        'end_datetime',
        'setup_start',
        'breakdown_end',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => EventStatusEnum::class,
        'event_type' => EventTypeEnum::class,
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'setup_start' => 'datetime',
        'breakdown_end' => 'datetime',
    ];

    public function opportunity(): BelongsTo
    {
        return $this->belongsTo(Opportunity::class);
    }

    public function functions(): HasMany
    {
        return $this->hasMany(EventFunction::class);
    }
}
