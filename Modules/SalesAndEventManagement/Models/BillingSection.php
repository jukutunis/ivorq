<?php

namespace Modules\SalesAndEventManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasAuditColumns;

class BillingSection extends Model
{
    use HasUlids, HasAuditColumns;

    protected $table = 'billing_sections';

    protected $fillable = [
        'operational_package_id',
        'deposit_schedule',
        'cancellation_policy',
    ];

    public function operationalPackage(): BelongsTo
    {
        return $this->belongsTo(OperationalPackage::class, 'operational_package_id');
    }
}
