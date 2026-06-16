<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Modules\FunctionSpace\Enums\FunctionSpaceBookingStatusEnum;
use Modules\SalesAndEventManagement\Models\EventFunction;

class FunctionSpaceBooking extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $fillable = [
        'venue_id',
        'event_function_id',
        'start_datetime',
        'end_datetime',
        'status',
    ];

    protected $casts = [
        'start_datetime' => 'datetime',
        'end_datetime' => 'datetime',
        'status' => FunctionSpaceBookingStatusEnum::class,
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function eventFunction()
    {
        return $this->belongsTo(EventFunction::class);
    }
}
