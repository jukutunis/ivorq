<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Shared\Traits\HasAuditColumns;

class VenueCombination extends Model
{
    use HasUlids, HasAuditColumns;

    protected $fillable = [
        'parent_venue_id',
        'child_venue_id',
    ];

    public function parentVenue()
    {
        return $this->belongsTo(Venue::class, 'parent_venue_id');
    }

    public function childVenue()
    {
        return $this->belongsTo(Venue::class, 'child_venue_id');
    }
}
