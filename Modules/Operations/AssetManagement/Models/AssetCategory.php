<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;

class AssetCategory extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'asset_categories';

    protected $guarded = ['id'];

    public function assetTypes(): HasMany
    {
        return $this->hasMany(AssetType::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
