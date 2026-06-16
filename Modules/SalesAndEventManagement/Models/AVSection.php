<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasAuditColumns;

class AVSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'av_sections';

    protected $fillable = [
        'operational_package_id',
        'equipment_required',
        'technician_required',
    ];

    protected $casts = [
        'technician_required' => 'boolean',
    ];

    public function operationalPackage(): BelongsTo
    {
        return $this->belongsTo(OperationalPackage::class, 'operational_package_id');
    }
}
