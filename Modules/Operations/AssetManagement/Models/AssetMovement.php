<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetMovement extends Model
{
    use HasUlids;

    protected $table = 'asset_movements';

    protected $guarded = ['id'];

    protected $casts = [
        'movement_date' => 'date',
    ];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
