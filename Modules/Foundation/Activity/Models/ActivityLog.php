<?php

namespace Modules\Foundation\Activity\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

/**
 * Immutable human-readable activity feed record. Once written, never changed or deleted.
 *
 * WRITE PATTERN — Why record() uses direct property assignment + save():
 *
 *   $guarded = ['*'] blocks ALL mass assignment. This is intentional — it
 *   prevents any code path other than ActivityService from writing activity logs.
 *   ActivityService is the single authorised caller of ActivityLog::record().
 *
 *   Do NOT use:
 *     ActivityLog::create([...])        — blocked by $guarded = ['*']
 *     $log->fill([...])->save()         — blocked by $guarded = ['*']
 *
 *   Always use:
 *     ActivityService::log(...)         — the only correct write path (calls record internally)
 *
 * DISTINCTION FROM AuditLog:
 *   ActivityLog — human-readable description, designed for activity feeds and
 *                 user-facing history ("John created Room 101").
 *   AuditLog    — technical diff of old/new field values, designed for compliance
 *                 and security review (who changed what and when).
 *
 * DATABASE INVARIANTS:
 *   - No updated_at column ($timestamps = false, only created_at is set manually)
 *   - No deleted_at column (no SoftDeletes — records are permanent)
 *   - No mass assignment ($guarded = ['*'])
 */
class ActivityLog extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'properties' => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The only sanctioned write path for activity records.
     *
     * Called internally by ActivityService::log(). Direct calls to this method
     * from outside ActivityService are a contract violation — use the service.
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

    public function subject(): MorphTo
    {
        return $this->morphTo();
    }

    public function causer(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
