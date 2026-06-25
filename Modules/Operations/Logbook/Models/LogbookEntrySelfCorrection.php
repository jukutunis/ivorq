<?php

namespace Modules\Operations\Logbook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class LogbookEntrySelfCorrection extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'logbook_entry_self_corrections';

    const UPDATED_AT = null;

    protected $fillable = [
        'property_id',
        'logbook_entry_id',
        'correction_reason',
        'correction_content',
        'corrected_by',
        'corrected_at',
    ];

    protected $casts = [
        'corrected_at' => 'datetime',
    ];

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LogbookEntry::class, 'logbook_entry_id');
    }

    public function corrector(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'corrected_by');
    }
}
