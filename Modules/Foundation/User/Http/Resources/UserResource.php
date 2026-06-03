<?php

namespace Modules\Foundation\User\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'            => $this->id,
            'property_id'   => $this->property_id,
            'name'          => $this->name,
            'email'         => $this->email,
            'phone'         => $this->phone,
            'avatar'        => $this->avatar,
            'department_id' => $this->department_id,
            'position_id'   => $this->position_id,
            'is_active'     => $this->is_active,
            'is_super_admin' => $this->isSuperAdmin(),
            'roles'         => $this->whenLoaded('roles', fn() => $this->roles->pluck('name')),
            'email_verified_at' => $this->email_verified_at,
            'created_at'    => $this->created_at,
            'updated_at'    => $this->updated_at,
        ];
    }
}
