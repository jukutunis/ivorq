<?php

namespace Modules\FunctionSpace\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\SoftDeletes;
use Shared\Traits\HasAuditColumns;
use Modules\FunctionSpace\Enums\VenueStatusEnum;

class Venue extends Model
{
    use HasUlids, SoftDeletes, HasAuditColumns;

    protected $fillable = [
        'property_id',
        'venue_category_id',
        'parent_venue_id',
        'name',
        'code',
        'status',
        'default_turnaround_minutes',
        'square_meter',
        'length',
        'width',
        'ceiling_height',
        'is_active',
    ];

    protected $casts = [
        'status' => VenueStatusEnum::class,
        'square_meter' => 'decimal:2',
        'length' => 'decimal:2',
        'width' => 'decimal:2',
        'ceiling_height' => 'decimal:2',
    ];

    public function category()
    {
        return $this->belongsTo(VenueCategory::class, 'venue_category_id');
    }

    public function parent()
    {
        return $this->belongsTo(Venue::class, 'parent_venue_id');
    }

    public function children()
    {
        return $this->hasMany(Venue::class, 'parent_venue_id');
    }

    public function combinations()
    {
        return $this->hasMany(VenueCombination::class, 'parent_venue_id');
    }

    public function capacities()
    {
        return $this->hasMany(VenueCapacity::class);
    }

    public function features()
    {
        return $this->belongsToMany(VenueFeature::class, 'venue_feature_assignments', 'venue_id', 'venue_feature_id');
    }

    public function maintenanceBlocks()
    {
        return $this->hasMany(VenueMaintenanceBlock::class);
    }

    public function bookings()
    {
        return $this->hasMany(FunctionSpaceBooking::class);
    }
}
