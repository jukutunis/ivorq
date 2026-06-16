<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasAuditColumns;

class SpecialRequestSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'special_request_sections';

    protected $fillable = [
        'operational_package_id',
        'request_details',
    ];

    public function operationalPackage(): BelongsTo
    {
        return $this->belongsTo(OperationalPackage::class, 'operational_package_id');
    }
}
