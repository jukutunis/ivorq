<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

/**
 * Immutable two-dimension status log for room lifecycle events.
 *
 * WRITE PATTERN — only RoomStatusHistory::record() may write to this table.
 *
 *   $guarded = ['*'] blocks ALL mass assignment. The only sanctioned write
 *   path is RoomStatusHistory::record(), called from RecordRoomHistory listener.
 *   Direct use of create() or fill() is a contract violation.
 *
 * DATABASE INVARIANTS:
 *   - No updated_at column ($timestamps = false, only created_at)
 *   - No deleted_at column (no SoftDeletes — records are permanent)
 *   - No mass assignment ($guarded = ['*'])
 *   - property_id stored for multi-tenancy queries, no FK constraint (log survives property changes)
 *   - room_id is nullable — records survive room deletion (SET NULL)
 *   - performed_by is nullable — records survive user deletion (SET NULL)
 *   - status_field discriminates between 'cleanliness' and 'occupancy' changes
 */
class RoomStatusHistory extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * The only sanctioned write path for room status history records.
     *
     * Sets attributes directly on the instance to bypass $guarded = ['*'].
     * created_at is set explicitly because $timestamps = false prevents auto-fill.
     *
     * Expected keys: property_id, room_id, status_field, from_status, to_status,
     *                action, performed_by, remarks (optional)
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

    public function room(): BelongsTo
    {
        return $this->belongsTo(Room::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
