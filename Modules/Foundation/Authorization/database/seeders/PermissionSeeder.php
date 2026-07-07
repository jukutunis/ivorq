<?php

namespace Modules\Foundation\Authorization\Database\Seeders;

use Illuminate\Database\Seeder;
use Modules\Foundation\Authorization\Models\Permission;

class PermissionSeeder extends Seeder
{
    public function run(): void
    {
        $permissions = [
            // Property
            'property.view', 'property.create', 'property.edit', 'property.delete',

            // Department
            'department.view', 'department.create', 'department.edit', 'department.delete',

            // User
            'user.view', 'user.create', 'user.edit', 'user.delete',

            // Role
            'role.view', 'role.create', 'role.edit', 'role.delete',
            'system.finance-role-assignment.manage',

            // Audit
            'audit.view',

            // Task
            'task.view', 'task.create', 'task.assign', 'task.complete', 'task.cancel', 'task.delete',

            // Activity
            'activity.view',

            // Logbook & Supervisory Scope
            'logbook.clarify', 'department.supervisors.manage',

            // Finance & GL Review Lifecycle
            'finance.journal-candidate.review',
            'finance.journal-candidate.materialize-draft',
            'finance.journal-entry-draft.authorize-finalization',
            'finance.journal-entry.post',
            'finance.payables.supplier-invoice.register',
            'finance.payables.supplier-invoice.review-exception',
            'finance.payables.supplier-invoice.approve',
            'finance.payables.grni-clearing.candidate.create',
            'finance.payables.payment-proposal.create',
            'finance.payables.payment-proposal.cancel',
            'finance.payables.payment-proposal.submit',
            'finance.payables.payment-proposal.approve',
            'finance.general-cashier.session.open',
            'finance.general-cashier.payment.execute',
            'finance.payables.supplier-payment.candidate.create',
            'finance.general-cashier.cash-count.record',
            'finance.general-cashier.cash-baseline.create',
            'finance.general-cashier.cash-reconciliation.perform',
            'finance.banking.source-account.register',
            'finance.banking.statement-line.register',
            'finance.banking.reconciliation.manual',
            'finance.general-cashier.payment.void',
            'finance.general-cashier.cash-return.record',
            'finance.general-cashier.cash-payment-reversal.create',
            'finance.payables.ap-settlement.allocate',
            'finance.fx-rate.record',
            'finance.fx-rate.approve',
            'finance.fx-adjustment-candidate.create',
            'finance.fx-adjustment.view',
            'finance.payment-adjustment-config.record',
            'finance.payment-adjustment-config.approve',

            // Banking — Migration Control Plane
            'finance.banking.migration.view',
            'finance.banking.migration.manage',
            'finance.banking.migration.mapping.review',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
