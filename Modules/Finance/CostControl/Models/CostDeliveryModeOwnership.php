<?php

namespace Modules\Finance\CostControl\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Finance\CostControl\Enums\CostDeliveryMode;
use Shared\Traits\HasUlid;

class CostDeliveryModeOwnership extends Model
{
    use HasUlid;

    protected $table = 'cost_delivery_mode_ownerships';

    protected $fillable = [
        'property_id',
        'item_id',
        'enrollment_group_id',
        'delivery_mode',
        'ownership_version',
        'activated_cutover_id',
        'established_by',
        'established_at',
        'changed_by',
        'changed_at',
    ];

    protected $casts = [
        'delivery_mode' => CostDeliveryMode::class,
        'ownership_version' => 'integer',
        'established_at' => 'datetime',
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
        'updated_at' => 'datetime',
    ];

    public function enrollmentGroup(): BelongsTo
    {
        return $this->belongsTo(CostAuthorityEnrollmentGroup::class, 'enrollment_group_id');
    }
}
