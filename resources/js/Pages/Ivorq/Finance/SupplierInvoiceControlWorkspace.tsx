import React from 'react';
import { usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';

declare const route: any;

interface Invoice {
  id: string;
  vendor_invoice_number: string;
  vendor_name: string | null;
  invoice_date: string | null;
  due_date: string | null;
  grand_total_amount: string;
  subtotal_amount: string;
  tax_amount: string;
  amount_paid: string;
  status: string;
  status_label: string;
  payment_status: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  line_count: number;
}

interface Props {
  invoices: Invoice[];
}

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/payables/supplier-invoices', label: 'Supplier Invoices' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

function statusBadge(status: string): BadgeStatus {
  switch (status) {
    case 'PENDING': return 'pending';
    case 'APPROVED': return 'ready';
    case 'REJECTED': return 'overdue';
    case 'VOIDED': return 'vacant';
    default: return 'vacant';
  }
}

export default function SupplierInvoiceControlWorkspace({ invoices }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  const pendingCount = invoices.filter((i) => i.status === 'PENDING').length;
  const approvedCount = invoices.filter((i) => i.status === 'APPROVED').length;
  const rejectedCount = invoices.filter((i) => i.status === 'REJECTED').length;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / Supplier Invoices" />

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <div style={{ fontSize: '12px', fontWeight: 600, marginBottom: '4px' }}>Summary</div>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Pending: {pendingCount}</div>
                <div>Approved: {approvedCount}</div>
                <div>Rejected: {rejectedCount}</div>
                <div>Total: {invoices.length}</div>
              </div>
            </div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div style={{
              border: `1px solid var(--${flash.success ? 'ready-green' : 'critical-red'})`,
              borderRadius: '6px', padding: '10px 12px', marginBottom: '14px',
              color: `var(--${flash.success ? 'ready-green' : 'critical-red'})`,
              background: 'var(--surface-card)', fontSize: '13px', fontWeight: 600,
            }}>{flash.success || flash.error}</div>
          )}

          {invoices.length === 0 ? (
            <AttentionArea title="Supplier Invoices" badgeText="0" badgeType="inspection" areaType="inspection">
              <div style={{ color: 'var(--text-secondary)', fontSize: '13px', padding: '20px 0' }}>
                No supplier invoices for the current property.
              </div>
            </AttentionArea>
          ) : (
            <AttentionArea title="Supplier Invoices" badgeText={`${invoices.length}`} badgeType="inspection" areaType="inspection">
              <div style={{ display: 'grid', gap: '10px' }}>
                {invoices.map((inv) => (
                  <div key={inv.id} style={{
                    border: '1px solid var(--border-default)', borderRadius: '6px',
                    padding: '12px', background: 'var(--surface-card)',
                  }}>
                    <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'start', marginBottom: '8px' }}>
                      <div>
                        <div style={{ fontWeight: 700, fontSize: '13px' }}>{inv.vendor_invoice_number}</div>
                        <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                          {inv.vendor_name || 'Unknown Vendor'} — {inv.grand_total_amount}
                        </div>
                      </div>
                      <StatusBadge status={statusBadge(inv.status)}>{inv.status_label}</StatusBadge>
                    </div>

                    <div style={{ fontSize: '11px', color: 'var(--text-secondary)', display: 'flex', gap: '16px', borderTop: '1px solid var(--border-default)', paddingTop: '8px' }}>
                      {inv.invoice_date && <span>Date: {inv.invoice_date}</span>}
                      {inv.due_date && <span>Due: {inv.due_date}</span>}
                      <span>Lines: {inv.line_count}</span>
                      {inv.payment_status && <span>Payment: {inv.payment_status}</span>}
                    </div>

                    {inv.approved_at && (
                      <div style={{ fontSize: '11px', color: 'var(--ready-green)', marginTop: '4px' }}>
                        Approved: {inv.approved_at}
                      </div>
                    )}
                    {inv.rejected_at && (
                      <div style={{ fontSize: '11px', color: 'var(--critical-red)', marginTop: '4px' }}>
                        Rejected: {inv.rejected_at}
                        {inv.rejection_reason && ` — ${inv.rejection_reason}`}
                      </div>
                    )}
                  </div>
                ))}
              </div>
            </AttentionArea>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

SupplierInvoiceControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
