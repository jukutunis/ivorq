<?php

namespace Modules\Foundation\Approval\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Approval\Models\ApprovalWorkflow;
use Modules\Foundation\Approval\Models\ApprovalStep;
use Modules\Foundation\Approval\Models\ApprovalStepAssignee;
use Modules\Foundation\Approval\Models\ApprovalMatrixRule;
use Modules\Foundation\Property\Models\Property;
use Modules\Foundation\User\Models\User;

class ApprovalSeeder extends Seeder
{
    public function run(): void
    {
        $properties = Property::all();
        $adminUser = User::where('email', 'admin@ivorq.com')->first();

        foreach ($properties as $property) {
            $workflow = ApprovalWorkflow::create([
                'property_id' => $property->id,
                'name' => 'Demo Foundation Approval',
                'approvable_type' => 'App\Models\DummyDocument', // placeholder
                'is_active' => true,
            ]);

            $step1 = ApprovalStep::create([
                'workflow_id' => $workflow->id,
                'sequence' => 1,
                'name' => 'Department Review',
                'required_approvals' => 1,
            ]);

            ApprovalStepAssignee::create([
                'step_id' => $step1->id,
                'assignee_type' => 'MATRIX_RULE',
            ]);

            if ($adminUser) {
                ApprovalMatrixRule::create([
                    'property_id' => $property->id,
                    'module' => 'Purchasing',
                    'document_type' => 'DummyDocument',
                    'assignee_type' => 'USER',
                    'user_id' => $adminUser->id,
                ]);
            }
        }
    }
}
