<?php

namespace Modules\Operations\Zoning\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

/**
 * Immutable domain narrative record for zone lifecycle events.
 *
 * WRITE PATTERN — Why record() uses direct property assignment + save():
 *
 *   $guarded = ['*'] blocks ALL mass assignment. The only sanctioned write
 *   path is ZoneHistory::record(), called exclusively from RecordZoneHistory
 *   listener. Direct use of create() or fill() is a contract violation.
 *
 *   Do NOT use:
 *     ZoneHistory::create([...])    — blocked by $guarded = ['*']
 *     $h->fill([...])->save()       — blocked by $guarded = ['*']
 *
 *   Always use:
 *     ZoneHistory::record([...])    — the only correct write path
 *
 * DATABASE INVARIANTS:
 *   - No updated_at column ($timestamps = false, only created_at)
 *   - No deleted_at column (no SoftDeletes — records are permanent)
 *   - No mass assignment ($guarded = ['*'])
 *   - zone_id is nullable — records survive zone deletion (SET NULL)
 *   - performed_by is nullable — records survive user deletion (SET NULL)
 */
class ZoneHistory extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    /**
     * The only sanctioned write path for zone history records.
     *
     * Sets attributes directly on the instance to bypass $guarded = ['*'].
     * created_at is set explicitly because $timestamps = false prevents auto-fill.
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

    public function zone(): BelongsTo
    {
        return $this->belongsTo(Zone::class);
    }

    public function performer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'performed_by');
    }
}
