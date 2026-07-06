import React, { useMemo, useState } from 'react';
import { Link, usePage } from '@inertiajs/react';
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
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

declare const route: any;

interface ProposalItem {
  id: string;
  source_amount: string;
  currency_code: string;
  invoice_number: string | null;
  invoice_id: string | null;
  journal_reference: string | null;
}

interface Proposal {
  id: string;
  proposal_number: string;
  vendor_name: string | null;
  currency_code: string;
  total_amount: string;
  status: string;
  status_label: string;
  submitted_by: string | null;
  submitted_at: string | null;
  cancelled_at: string | null;
  cancellation_reason: string | null;
  approved_at: string | null;
  rejected_at: string | null;
  items: ProposalItem[];
}

interface Props {
  proposals: Proposal[];
  permissions: {
    can_create: boolean;
    can_cancel: boolean;
  };
}

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/accounts-receivable', label: 'Accounts Receivable' },
  { href: '/finance/budget-watch', label: 'Budget Watch' },
  { href: '/finance/payables/ap-grni-settlement-control', label: 'AP/GRNI Settlement' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
  { href: '/finance/fx-access-management', label: 'FX Access Management' },
];

function statusBadge(status: string): BadgeStatus {
  switch (status) {
    case 'DRAFT':
      return 'draft';
    case 'PENDING_APPROVAL':
      return 'pending';
    case 'APPROVED':
      return 'ready';
    case 'REJECTED':
      return 'overdue';
    case 'CANCELLED':
      return 'vacant';
    default:
      return 'vacant';
  }
}

function statusColor(status: string): string {
  switch (status) {
    case 'APPROVED':
      return 'ready-green';
    case 'PENDING_APPROVAL':
      return 'pending-purple';
    case 'REJECTED':
      return 'critical-red';
    case 'CANCELLED':
      return 'neutral-slate';
    default:
      return 'inspection-blue';
  }
}

export default function PaymentProposalControlWorkspace({ proposals, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [selectedId, setSelectedId] = useState<string | null>(proposals[0]?.id ?? null);

  const draftCount = useMemo(() => proposals.filter((proposal) => proposal.status === 'DRAFT').length, [proposals]);
  const pendingCount = useMemo(() => proposals.filter((proposal) => proposal.status === 'PENDING_APPROVAL').length, [proposals]);
  const approvedCount = useMemo(() => proposals.filter((proposal) => proposal.status === 'APPROVED').length, [proposals]);
  const selectedProposal = proposals.find((proposal) => proposal.id === selectedId) ?? proposals[0] ?? null;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Payment Proposal Control">
        <Link
          href={route('finance.payables.ap-grni-settlement-control')}
          preserveScroll
          className="btn btn-secondary"
        >
          <Icon name="file-text" /> AP/GRNI Settlement
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <label className="filter-label">Current Property Scope</label>
              <div className="finance-context-note">
                Payment proposals projected by the server for the active property.
              </div>
            </div>

            <div className="filter-group">
              <label className="filter-label">Lifecycle Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Draft: {draftCount}</div>
                <div>Pending Approval: {pendingCount}</div>
                <div>Approved: {approvedCount}</div>
              </div>
            </div>

            <div className="filter-group">
              <label className="filter-label">Selected Proposal</label>
              <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
                {selectedProposal?.proposal_number || 'No proposal selected'}
              </div>
              <div className="finance-context-note">
                {selectedProposal?.vendor_name || 'Vendor evidence unavailable'}
              </div>
            </div>

            {permissions.can_create && (
              <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
                <div className="finance-context-note" style={{ marginBottom: '8px' }}>
                  Draft proposals are created from AP/GRNI settlement evidence.
                </div>
                <Link
                  href={route('finance.payables.ap-grni-settlement-control')}
                  className="btn btn-primary"
                  style={{ fontSize: '12px', width: '100%' }}
                >
                  <Icon name="plus" /> Create Draft
                </Link>
              </div>
            )}

            {!permissions.can_create && (
              <div className="finance-context-note">
                View authority is active. Proposal creation requires separate Finance permission.
              </div>
            )}
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div className={`finance-flash ${flash.success ? 'success' : 'error'}`}>{flash.success || flash.error}</div>
          )}

          <OperationalSnapshot>
            <SnapshotCard value={draftCount} label="Draft" statusColor="neutral-slate" />
            <SnapshotCard value={pendingCount} label="Pending Approval" statusColor="pending-purple" />
            <SnapshotCard value={approvedCount} label="Approved" statusColor="ready-green" />
            <SnapshotCard value={proposals.length} label="Total Proposals" statusColor="inspection-blue" />
          </OperationalSnapshot>

          {proposals.length === 0 && (
            <AttentionArea title="Payment Proposal Queue" badgeText="No Data" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No payment proposals exist for the current property. Draft proposals are created from AP/GRNI Settlement when AP liability journal entries are ready for payment.
              </div>
            </AttentionArea>
          )}

          {proposals.length > 0 && (
            <div className="finance-master-detail">
              <QueueList title="Payment Proposal Queue" count={proposals.length}>
                <div className="finance-queue-body">
                  {proposals.map((proposal) => (
                    <WorkCard
                      key={proposal.id}
                      className={selectedProposal?.id === proposal.id ? 'is-selected' : ''}
                      borderColor={statusColor(proposal.status)}
                      meta={
                        <>
                          <span>{proposal.submitted_at || proposal.approved_at || proposal.cancelled_at || 'Lifecycle date unavailable'}</span>
                          <StatusBadge status={statusBadge(proposal.status)}>{proposal.status_label}</StatusBadge>
                        </>
                      }
                      title={proposal.proposal_number}
                      detail={
                        <span>
                          {proposal.vendor_name || 'Unknown Vendor'}
                          <br />
                          {proposal.total_amount} {proposal.currency_code}
                        </span>
                      }
                      actions={
                        <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedId(proposal.id)}>
                          <Icon name="search" /> Evidence
                        </Button>
                      }
                    />
                  ))}
                </div>
              </QueueList>

              {selectedProposal ? (
                <ProposalEvidence proposal={selectedProposal} />
              ) : (
                <AttentionArea title="Selected Proposal Evidence" badgeText="None" badgeType="neutral" areaType="neutral">
                  <div className="finance-empty-state">Select a proposal to review supplier, source invoice, journal, and lifecycle evidence.</div>
                </AttentionArea>
              )}
            </div>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

function ProposalEvidence({ proposal }: { proposal: Proposal }) {
  return (
    <AttentionArea
      title="Selected Proposal Evidence"
      badgeText={proposal.status_label}
      badgeType={statusBadge(proposal.status)}
      areaType={proposal.status === 'REJECTED' || proposal.status === 'CANCELLED' ? 'warning' : 'inspection'}
    >
      <div className="finance-evidence-grid">
        <EvidenceCell label="Proposal" value={proposal.proposal_number} />
        <EvidenceCell label="Supplier" value={proposal.vendor_name || 'Unknown Vendor'} />
        <EvidenceCell label="Total" value={`${proposal.total_amount} ${proposal.currency_code}`} />
        <EvidenceCell label="Source Items" value={`${proposal.items.length}`} />
        <EvidenceCell label="Submitted By" value={proposal.submitted_by || 'N/A'} />
        <EvidenceCell label="Submitted At" value={proposal.submitted_at || 'N/A'} />
      </div>

      <div className="finance-evidence-section">
        <div className="finance-section-title">Supplier Invoice / Journal Reference</div>
        {proposal.items.length === 0 ? (
          <div className="finance-empty-state">No proposal item evidence is projected for this proposal.</div>
        ) : (
          <div className="finance-inline-items">
            {proposal.items.map((item) => (
              <div key={item.id} className="finance-inline-item">
                <span>
                  Invoice {item.invoice_number || 'N/A'}
                  {item.journal_reference && ` | Journal ${item.journal_reference}`}
                </span>
                <span>{item.source_amount} {item.currency_code}</span>
              </div>
            ))}
          </div>
        )}
      </div>

      <div className="finance-evidence-section">
        <div className="finance-section-title">Lifecycle / Exception</div>
        <div className="finance-evidence-list">
          <EvidenceRow label="Approved" value={proposal.approved_at || 'N/A'} />
          <EvidenceRow label="Rejected" value={proposal.rejected_at || 'N/A'} />
          <EvidenceRow label="Cancelled" value={proposal.cancelled_at || 'N/A'} />
          {proposal.cancellation_reason && <EvidenceRow label="Reason" value={proposal.cancellation_reason} />}
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

PaymentProposalControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
