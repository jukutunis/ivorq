<?php

namespace Modules\Operations\Logbook\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasUlid;
use Shared\Traits\BelongsToProperty;

class LogbookEntryFollowUpResolution extends Model
{
    use HasUlid, BelongsToProperty;

    protected $table = 'logbook_entry_follow_up_resolutions';

    const UPDATED_AT = null;

    protected $fillable = [
        'property_id',
        'logbook_entry_id',
        'resolution_note',
        'resolved_by',
        'resolved_at',
    ];

    protected $casts = [
        'resolved_at' => 'datetime',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\Property\Models\Property::class);
    }

    public function entry(): BelongsTo
    {
        return $this->belongsTo(LogbookEntry::class, 'logbook_entry_id');
    }

    public function resolver(): BelongsTo
    {
        return $this->belongsTo(\Modules\Foundation\User\Models\User::class, 'resolved_by');
    }
}
