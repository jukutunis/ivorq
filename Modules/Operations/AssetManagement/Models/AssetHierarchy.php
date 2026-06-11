<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AssetHierarchy extends Model
{
    use HasUlids;

    protected $table = 'asset_hierarchies';

    protected $guarded = ['id'];

    public function ancestor(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'ancestor_id');
    }

    public function descendant(): BelongsTo
    {
        return $this->belongsTo(Asset::class, 'descendant_id');
    }
}
