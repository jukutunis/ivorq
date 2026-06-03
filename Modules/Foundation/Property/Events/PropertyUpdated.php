<?php

namespace Modules\Foundation\Property\Events;

use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Modules\Foundation\Property\Models\Property;

class PropertyUpdated
{
    use Dispatchable, SerializesModels;

    public function __construct(public readonly Property $property) {}
}
