<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesAndEventManagement\Enums\FunctionStatusEnum;

class EventFunction extends Model
{
    use HasUlids;

    protected $fillable = [
        'event_id',
        'function_name',
        'status',
        'start_datetime',
        'end_datetime',
        'setup_start',
        'breakdown_end',
        'created_by',
        'updated_by',
    ];

    protected $casts = [
        'status' => FunctionStatusEnum::class,
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'setup_start' => 'datetime',
        'breakdown_end' => 'datetime',
    ];

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class);
    }
}
