<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\AssetRequest;

class RejectAssetRequestRequest extends FormRequest
{
    public function authorize(): bool
    {
        $request = AssetRequest::find($this->route('req'));

        return $request && $this->user()->can('reject', $request);
    }

    public function rules(): array
    {
        return [
            'reason'      => ['required', 'string', 'max:1000'],
            'rejected_by' => ['prohibited'],
            'rejected_at' => ['prohibited'],
        ];
    }
}
