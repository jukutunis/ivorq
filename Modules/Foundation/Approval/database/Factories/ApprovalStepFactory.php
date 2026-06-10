<?php

namespace Modules\Foundation\Approval\Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;

class ApprovalStepFactory extends Factory
{
    protected $model = ApprovalStep::class;

    public function definition(): array
    {
        return [
            'workflow_id' => ApprovalWorkflow::factory(),
            'sequence_no' => 1,
            'role_name' => null,
            'permission_name' => null,
            'approval_limit' => null,
            'currency_code' => null,
            'is_required' => true,
        ];
    }
}
