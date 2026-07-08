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

            // Front Desk - Controlled Operational Lifecycle
            'frontdesk.arrival.view',
            'frontdesk.engineering-availability.view',
            'frontdesk.room-assignment.create',
            'frontdesk.check-in.execute',
            'frontdesk.in-house.view',
            'frontdesk.room-move.execute',
            'frontdesk.checkout-readiness.view',

            // Engineering - Controlled Room Availability
            'engineering.room-availability.view',
            'engineering.room-availability.block',
            'engineering.room-availability.release',

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
            'finance.banking.migration.pilot.authorization.review',
            'finance.banking.migration.pilot.execution.execute',

            // Inventory — Controlled Ledger
            'inventory.ledger.view',

            // Inventory — Purchasing
            'inventory.purchasing.requisition.create',
            'inventory.purchasing.requisition.approve',
            'inventory.purchasing.purchase-order.create',
            'inventory.purchasing.purchase-order.approve',
            'inventory.purchasing.goods-receipt.receive',

            // Inventory — Transfers
            'inventory.transfer.create',
            'inventory.transfer.post',

            // Inventory — Issues
            'inventory.issue.create',
            'inventory.issue.post',

            // Inventory — Stock Counts
            'inventory.stock-count.create',
            'inventory.stock-count.approve',
            'inventory.stock-count.post',

            // Inventory — Adjustments
            'inventory.adjustment.create',
            'inventory.adjustment.approve',
            'inventory.adjustment.post',

            // Inventory — Cost Control (read-only)
            'inventory.cost-control.view',
        ];

        foreach ($permissions as $name) {
            Permission::firstOrCreate(['name' => $name, 'guard_name' => 'web']);
        }
    }
}
