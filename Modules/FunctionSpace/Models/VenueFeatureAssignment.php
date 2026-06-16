<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Shared\Traits\HasAuditColumns;

class VenueFeatureAssignment extends Model
{
    use HasUlids, HasAuditColumns;

    protected $fillable = [
        'venue_id',
        'venue_feature_id',
    ];

    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function feature()
    {
        return $this->belongsTo(VenueFeature::class, 'venue_feature_id');
    }
}
