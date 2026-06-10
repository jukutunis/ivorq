<?php

namespace Modules\Foundation\Approval\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ApprovalStepResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'workflow_id' => $this->workflow_id,
            'sequence_no' => $this->sequence_no,
            'role_name' => $this->role_name,
            'permission_name' => $this->permission_name,
            'approval_limit' => $this->approval_limit,
            'currency_code' => $this->currency_code,
            'is_required' => $this->is_required,
        ];
    }
}
