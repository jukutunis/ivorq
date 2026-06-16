<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Shared\Traits\HasAuditColumns;

class VenueCapacity extends Model
{
    use HasUlids, HasAuditColumns;

    protected $fillable = [
        'venue_id',
        'setup_style_id',
        'maximum_capacity',
        'optimal_capacity',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function setupStyle()
    {
        return $this->belongsTo(SetupStyle::class);
    }
}
