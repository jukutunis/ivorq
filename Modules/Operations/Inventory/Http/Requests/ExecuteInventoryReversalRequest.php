<?php

namespace Modules\Operations\Inventory\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ExecuteInventoryReversalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return auth()->check() && auth()->user()->hasPermissionTo('inventory.reversal.execute');
    }

    public function rules(): array
    {
        return [
            'execution_idempotency_key' => ['required', 'string', 'min:1'],
        ];
    }
}
