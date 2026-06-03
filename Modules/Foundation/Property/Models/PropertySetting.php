<?php

namespace Modules\Foundation\Property\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Shared\Traits\HasAuditColumns;
use Shared\Traits\HasUlid;

class PropertySetting extends Model
{
    use HasUlid, HasAuditColumns;

    protected $fillable = [
        'property_id',
        'group',
        'key',
        'value',
        'is_public',
    ];

    protected $casts = [
        'is_public' => 'boolean',
    ];

    public function property(): BelongsTo
    {
        return $this->belongsTo(Property::class);
    }
}
