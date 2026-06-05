<?php

namespace Modules\Operations\Engineering\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Modules\Operations\Engineering\Models\PreventiveMaintenance;

class GeneratePreventiveMaintenanceTaskRequest extends FormRequest
{
    public function authorize(): bool
    {
        $pm = PreventiveMaintenance::find($this->route('pm'));

        return $pm && $this->user()->can('generateTask', $pm);
    }

    public function rules(): array
    {
        return [
            // Defaults to today() in PreventiveMaintenanceService::generateTask() when absent.
            'scheduled_date' => ['nullable', 'date'],
        ];
    }
}
