<?php

namespace Modules\Operations\Housekeeping\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Eloquent\Concerns\HasUlids;

class LostAndFoundItem extends Model
{
    use SoftDeletes, HasUlids;

    protected $table = 'lost_and_found_items';

    protected $fillable = [
        'property_id',
        'reference_number',
        'room_id',
        'location_description',
        'found_by_user_id',
        'category_id',
        'status',
        'description',
        'chain_of_custody',
        'supervisor_approval_id',
    ];

    protected $casts = [
        'chain_of_custody' => 'array',
    ];
}