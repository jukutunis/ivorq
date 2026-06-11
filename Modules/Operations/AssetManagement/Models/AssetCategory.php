<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Operations\AssetManagement\Database\Factories\AssetCategoryFactory;

class AssetCategory extends Model
{
    use HasUlids, SoftDeletes, HasFactory;

    protected $table = 'asset_categories';

    protected $guarded = ['id'];

    protected static function newFactory()
    {
        return AssetCategoryFactory::new();
    }


    public function assetTypes(): HasMany
    {
        return $this->hasMany(AssetType::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
