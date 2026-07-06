import React, { useMemo, useState } from 'react';
import { usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import QueueList from '../../../Components/Ivorq/patterns/QueueList';
import WorkCard from '../../../Components/Ivorq/housekeeping/WorkCard';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';

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
  const [selectedId, setSelectedId] = useState<string | null>(invoices[0]?.id ?? null);

  const pendingCount = useMemo(() => invoices.filter((invoice) => invoice.status === 'PENDING').length, [invoices]);
  const approvedCount = useMemo(() => invoices.filter((invoice) => invoice.status === 'APPROVED').length, [invoices]);
  const rejectedCount = useMemo(() => invoices.filter((invoice) => invoice.status === 'REJECTED').length, [invoices]);
  const selectedInvoice = invoices.find((invoice) => invoice.id === selectedId) ?? invoices[0] ?? null;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Supplier Invoice Control" />

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <label className="filter-label">Current Property Scope</label>
              <div className="finance-context-note">
                Supplier invoice evidence projected for the active property.
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Lifecycle Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Pending: {pendingCount}</div>
                <div>Approved: {approvedCount}</div>
                <div>Rejected: {rejectedCount}</div>
                <div>Total: {invoices.length}</div>
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Selected Invoice</label>
              <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
                {selectedInvoice?.vendor_invoice_number || 'No invoice selected'}
              </div>
              <div className="finance-context-note">
                {selectedInvoice?.vendor_name || 'Supplier evidence unavailable'}
              </div>
            </div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div className={`finance-flash ${flash.success ? 'success' : 'error'}`}>{flash.success || flash.error}</div>
          )}

          <OperationalSnapshot>
            <SnapshotCard value={pendingCount} label="Pending" statusColor="pending-purple" />
            <SnapshotCard value={approvedCount} label="Approved" statusColor="ready-green" />
            <SnapshotCard value={rejectedCount} label="Rejected" statusColor="critical-red" />
            <SnapshotCard value={invoices.length} label="Total Invoices" statusColor="inspection-blue" />
          </OperationalSnapshot>

          {invoices.length === 0 && (
            <AttentionArea title="Supplier Invoice Queue" badgeText="No Data" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No supplier invoices are projected for the current property.
              </div>
            </AttentionArea>
          )}

          {invoices.length > 0 && (
            <div className="finance-master-detail">
              <QueueList title="Supplier Invoice Queue" count={invoices.length}>
                <div className="finance-queue-body">
                  {invoices.map((invoice) => (
                    <WorkCard
                      key={invoice.id}
                      className={selectedInvoice?.id === invoice.id ? 'is-selected' : ''}
                      borderColor={invoice.status === 'REJECTED' ? 'critical-red' : invoice.status === 'APPROVED' ? 'ready-green' : 'pending-purple'}
                      meta={
                        <>
                          <span>{invoice.invoice_date || 'Date unavailable'}</span>
                          <StatusBadge status={statusBadge(invoice.status)}>{invoice.status_label}</StatusBadge>
                        </>
                      }
                      title={invoice.vendor_invoice_number}
                      detail={
                        <span>
                          {invoice.vendor_name || 'Unknown Vendor'}
                          <br />
                          {invoice.grand_total_amount}
                        </span>
                      }
                      actions={
                        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedId(invoice.id)}>
                          <Icon name="search" /> Evidence
                        </Button>
                      }
                    />
                  ))}
                </div>
              </QueueList>

              {selectedInvoice ? (
                <InvoiceEvidence invoice={selectedInvoice} />
              ) : (
                <AttentionArea title="Selected Invoice Evidence" badgeText="None" badgeType="neutral" areaType="neutral">
                  <div className="finance-empty-state">Select an invoice to review supplier, invoice, and lifecycle evidence.</div>
                </AttentionArea>
              )}
            </div>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

function InvoiceEvidence({ invoice }: { invoice: Invoice }) {
  return (
    <AttentionArea
      title="Selected Invoice Evidence"
      badgeText={invoice.status_label}
      badgeType={statusBadge(invoice.status)}
      areaType={invoice.status === 'REJECTED' ? 'warning' : 'inspection'}
    >
      <div className="finance-evidence-grid">
        <EvidenceCell label="Supplier Invoice" value={invoice.vendor_invoice_number} />
        <EvidenceCell label="Supplier" value={invoice.vendor_name || 'Unknown Vendor'} />
        <EvidenceCell label="Invoice Date" value={invoice.invoice_date || 'N/A'} />
        <EvidenceCell label="Due Date" value={invoice.due_date || 'N/A'} />
        <EvidenceCell label="Grand Total" value={invoice.grand_total_amount} />
        <EvidenceCell label="Payment Status" value={invoice.payment_status || 'N/A'} />
      </div>

      <div className="finance-evidence-section">
        <div className="finance-section-title">Supplier Invoice</div>
        <div className="finance-evidence-list">
          <EvidenceRow label="Subtotal" value={invoice.subtotal_amount} />
          <EvidenceRow label="Tax" value={invoice.tax_amount} />
          <EvidenceRow label="Amount Paid" value={invoice.amount_paid} />
          <EvidenceRow label="Line Count" value={`${invoice.line_count}`} />
        </div>
      </div>

      <div className="finance-evidence-section">
        <div className="finance-section-title">Lifecycle / History</div>
        <div className="finance-evidence-list">
          <EvidenceRow label="Approval" value={invoice.approved_at || 'N/A'} />
          <EvidenceRow label="Rejection" value={invoice.rejected_at || 'N/A'} />
          {invoice.rejection_reason && <EvidenceRow label="Exception Reason" value={invoice.rejection_reason} />}
        </div>
      </div>
    </AttentionArea>
  );
}

function EvidenceCell({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div style={{ color: 'var(--text-secondary)', fontSize: '11px', fontWeight: 700, textTransform: 'uppercase' }}>{label}</div>
      <div style={{ color: 'var(--text-primary)', fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>{value}</div>
    </div>
  );
}

function EvidenceRow({ label, value }: { label: string; value: string }) {
  return (
    <div className="finance-evidence-row">
      <span>{label}</span>
      <span>{value}</span>
    </div>
  );
}

SupplierInvoiceControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
