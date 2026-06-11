<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetRelationship extends Model
{
    use HasUlids;

    protected $table = 'asset_relationships';

    protected $guarded = ['id'];

    public function sourceAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'source_asset_id');
    }

    public function targetAsset(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'target_asset_id');
    }
}
