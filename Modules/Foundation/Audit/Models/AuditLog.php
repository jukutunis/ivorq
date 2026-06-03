<?php

namespace Modules\Foundation\Audit\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;
use Modules\Foundation\User\Models\User;
use Shared\Traits\HasUlid;

/**
 * Immutable audit trail record. Once written, never changed or deleted.
 *
 * WRITE PATTERN — Why record() uses direct property assignment + save():
 *
 *   $guarded = ['*'] blocks ALL mass assignment. This is intentional — it
 *   prevents accidental writes via Model::create([...]) or $model->fill([...]).
 *   The only sanctioned write path is AuditLog::record(), which bypasses the
 *   guard by setting properties directly on the instance before calling save().
 *
 *   Do NOT use:
 *     AuditLog::create([...])           — blocked by $guarded = ['*']
 *     $log->fill([...])->save()         — blocked by $guarded = ['*']
 *     $log->forceFill([...])->save()    — works but bypasses the ULID boot hook
 *
 *   Always use:
 *     AuditLog::record([...])           — the only correct write path
 *
 * READ PATTERN — query via AuditLogRepository only. Never modify returned records.
 *
 * DATABASE INVARIANTS:
 *   - No updated_at column ($timestamps = false, only created_at is set manually)
 *   - No deleted_at column (no SoftDeletes — records are permanent)
 *   - No mass assignment ($guarded = ['*'])
 */
class AuditLog extends Model
{
    use HasUlid;

    public $timestamps = false;

    protected $guarded = ['*'];

    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'tags'       => 'array',
        'created_at' => 'datetime',
    ];

    /**
     * The only sanctioned write path for audit records.
     *
     * Sets each attribute directly on the instance to bypass $guarded = ['*'],
     * then calls save(). The ULID is set by the HasUlid boot hook on 'creating'.
     * created_at is set here explicitly because $timestamps = false means Eloquent
     * will not set it automatically.
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

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
