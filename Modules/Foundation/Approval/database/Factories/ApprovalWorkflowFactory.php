<?php

namespace Modules\Foundation\Approval\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Property\Models\Property;

class ApprovalWorkflowFactory extends Factory
{
    protected $model = ApprovalWorkflow::class;

    public function definition(): array
    {
        return [
            'property_id' => (string) \Illuminate\Support\Str::ulid(),
            'workflow_name' => $this->faker->words(3, true),
            'module' => 'Purchasing',
            'is_active' => true,
        ];
    }
}
