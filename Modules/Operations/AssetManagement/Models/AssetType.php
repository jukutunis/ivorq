<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Modules\Operations\AssetManagement\Database\Factories\AssetTypeFactory;

class AssetType extends Model
{
    use HasUlids, SoftDeletes, HasFactory;

    protected $table = 'asset_types';

    protected $guarded = ['id'];

    protected static function newFactory()
    {
        return AssetTypeFactory::new();
    }


    public function assetCategory(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class);
    }

    public function assets(): HasMany
    {
        return $this->hasMany(Asset::class);
    }
}
