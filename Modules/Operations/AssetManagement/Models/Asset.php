<?php

namespace Modules\Operations\AssetManagement\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Asset extends Model
{
    use HasUlids, SoftDeletes;

    protected $table = 'assets';

    protected $guarded = ['id'];

    protected $casts = [
        'purchase_date' => 'date',
        'installation_date' => 'date',
        'commissioning_date' => 'date',
        'disposal_date' => 'date',
    ];

    public function category(): BelongsTo
    {
        return $this->belongsTo(AssetCategory::class, 'asset_category_id');
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(AssetType::class, 'asset_type_id');
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(AssetGroup::class, 'asset_group_id');
    }

    public function movements(): HasMany
    {
        return $this->hasMany(AssetMovement::class);
    }

    public function watchers(): HasMany
    {
        return $this->hasMany(AssetWatcher::class);
    }

    public function warranties(): HasMany
    {
        return $this->hasMany(AssetWarranty::class);
    }

    public function vendors(): HasMany
    {
        return $this->hasMany(AssetVendor::class);
    }

    public function commissionings(): HasMany
    {
        return $this->hasMany(AssetCommissioning::class);
    }

    public function descendantHierarchies(): HasMany
    {
        return $this->hasMany(AssetHierarchy::class, 'ancestor_id');
    }

    public function ancestorHierarchies(): HasMany
    {
        return $this->hasMany(AssetHierarchy::class, 'descendant_id');
    }

    public function sourceRelationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'source_asset_id');
    }

    public function targetRelationships(): HasMany
    {
        return $this->hasMany(AssetRelationship::class, 'target_asset_id');
    }
}
