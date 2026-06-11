<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetWatcher extends Model
{
    use HasUlids;

    protected $table = 'asset_watchers';

    protected $guarded = ['id'];

    public function asset(): BelongsTo
    {
        return $this->belongsTo(Asset::class);
    }
}
