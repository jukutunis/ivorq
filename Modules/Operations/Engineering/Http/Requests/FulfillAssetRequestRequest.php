<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\AssetRequest;

class FulfillAssetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = AssetRequest::find($this->route('req'));

        return $request && $this->user()->can('fulfill', $request);
    }

    public function rules(): array
    {
        return [
            // fulfilled_by is always derived from auth() in AssetRequestService::fulfill().
            'fulfilled_by' => ['prohibited'],
            'fulfilled_at' => ['prohibited'],
        ];
    }
}
