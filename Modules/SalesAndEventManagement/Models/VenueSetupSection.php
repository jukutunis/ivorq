<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\SalesAndEventManagement\Enums\VenueTypeEnum;
use Shared\Traits\HasAuditColumns;

class VenueSetupSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'venue_setup_sections';

    protected $fillable = [
        'operational_package_id',
        'venue_type',
        'setup_style',
        'expected_capacity',
    ];

    protected $casts = [
        'venue_type' => VenueTypeEnum::class,
    ];

    public function operationalPackage(): BelongsTo
    {
        return $this->belongsTo(OperationalPackage::class, 'operational_package_id');
    }
}
