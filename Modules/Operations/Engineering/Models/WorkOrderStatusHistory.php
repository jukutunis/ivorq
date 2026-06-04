<?php

namespace Modules\Operations\Engineering\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

/**
 * Immutable status transition log for work order lifecycle events.
 *
 * WRITE PATTERN — only WorkOrderStatusHistory::record() may write to this table.
 *
 *   $guarded = ['*'] blocks ALL mass assignment. The only sanctioned write
 *   path is WorkOrderStatusHistory::record(), called from RecordWorkOrderHistory
 *   listener. Direct use of create() or fill() is a contract violation.
 *
 * DATABASE INVARIANTS:
 *   - No updated_at ($timestamps = false, created_at managed manually)
 *   - No deleted_at (no SoftDeletes — records are permanent)
 *   - No BelongsToProperty global scope (always accessed via workOrder relationship)
 *   - No HasAuditColumns (table has created_by only, no updated_by)
 *   - work_order_id cascades on hard delete
 *   - changed_by is nullable — records survive user deletion (SET NULL)
 */
class WorkOrderStatusHistory extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'changed_at' => 'datetime',
        'created_at' => 'datetime',
    ];

    /**
     * The only sanctioned write path for work order status history records.
     *
     * Sets attributes directly on the instance to bypass $guarded = ['*'].
     * created_at is set explicitly because $timestamps = false prevents auto-fill.
     *
     * Expected keys: property_id, work_order_id, from_status, to_status,
     *                changed_by, changed_at, remarks (optional), created_by (optional)
     */
    public static function record(array $attributes): static
    {
        $instance = new static();
        foreach ($attributes as $key => $value) {
            $instance->$key = $value;
        }
        $instance->created_at = now();
        $instance->save();

        return $instance;
    }

    public function workOrder(): BelongsTo
    {
        return $this->belongsTo(WorkOrder::class);
    }

    public function changedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }
}
