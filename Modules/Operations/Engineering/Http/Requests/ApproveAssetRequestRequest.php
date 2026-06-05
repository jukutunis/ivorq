<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\AssetRequest;

class ApproveAssetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = AssetRequest::find($this->route('req'));

        return $request && $this->user()->can('approve', $request);
    }

    public function rules(): array
    {
        return [
            // approved_by is always derived from auth() in AssetRequestService::approve().
            'approved_by' => ['prohibited'],
            'approved_at' => ['prohibited'],
        ];
    }
}
