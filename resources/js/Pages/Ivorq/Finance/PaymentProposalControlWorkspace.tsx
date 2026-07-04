import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
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

export default function PaymentProposalControlWorkspace({ proposals, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  const draftCount = proposals.filter((p) => p.status === 'DRAFT').length;
  const pendingCount = proposals.filter((p) => p.status === 'PENDING_APPROVAL').length;
  const approvedCount = proposals.filter((p) => p.status === 'APPROVED').length;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / Payment Proposals">
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
              <div style={{ fontSize: '12px', fontWeight: 600, marginBottom: '4px' }}>
                Summary
              </div>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Draft: {draftCount}</div>
                <div>Pending Approval: {pendingCount}</div>
                <div>Approved: {approvedCount}</div>
              </div>
            </div>

            {permissions.can_create && (
              <div className="filter-group">
                <div style={{ fontSize: '11px', color: 'var(--text-secondary)', marginBottom: '8px' }}>
                  Draft proposals are created from the AP/GRNI Settlement workspace.
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
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>
                You do not have permission to create payment proposals.
              </div>
            )}
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div
              style={{
                border: `1px solid var(--${flash.success ? 'ready-green' : 'critical-red'})`,
                borderRadius: '6px',
                padding: '10px 12px',
                marginBottom: '14px',
                color: `var(--${flash.success ? 'ready-green' : 'critical-red'})`,
                background: 'var(--surface-card)',
                fontSize: '13px',
                fontWeight: 600,
              }}
            >
              {flash.success || flash.error}
            </div>
          )}

          {proposals.length === 0 && (
            <AttentionArea
              title="Payment Proposals"
              badgeText="0"
              badgeType="inspection"
              areaType="inspection"
            >
              <div style={{ color: 'var(--text-secondary)', fontSize: '13px', padding: '20px 0' }}>
                No payment proposals exist for the current property. Draft proposals are created
                from the AP/GRNI Settlement workspace when AP liability journal entries are ready
                for payment.
              </div>
            </AttentionArea>
          )}

          {proposals.length > 0 && (
            <AttentionArea
              title="Payment Proposals"
              badgeText={`${proposals.length} Proposals`}
              badgeType="inspection"
              areaType="inspection"
            >
              <div style={{ display: 'grid', gap: '10px' }}>
                {proposals.map((proposal) => (
                  <div
                    key={proposal.id}
                    style={{
                      border: '1px solid var(--border-default)',
                      borderRadius: '6px',
                      padding: '12px',
                      background: 'var(--surface-card)',
                    }}
                  >
                    <div
                      style={{
                        display: 'flex',
                        justifyContent: 'space-between',
                        alignItems: 'start',
                        marginBottom: '8px',
                      }}
                    >
                      <div>
                        <div style={{ fontWeight: 700, fontSize: '13px' }}>
                          {proposal.proposal_number}
                        </div>
                        <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                          {proposal.vendor_name || 'Unknown Vendor'}
                          {' — '}
                          {proposal.total_amount} {proposal.currency_code}
                        </div>
                      </div>
                      <StatusBadge status={statusBadge(proposal.status)}>
                        {proposal.status_label}
                      </StatusBadge>
                    </div>

                    {proposal.items.length > 0 && (
                      <div
                        style={{
                          borderTop: '1px solid var(--border-default)',
                          paddingTop: '8px',
                          marginTop: '4px',
                        }}
                      >
                        {proposal.items.map((item) => (
                          <div
                            key={item.id}
                            style={{
                              fontSize: '11px',
                              color: 'var(--text-secondary)',
                              display: 'flex',
                              justifyContent: 'space-between',
                              padding: '2px 0',
                            }}
                          >
                            <span>
                              Invoice: {item.invoice_number || '—'}
                              {item.journal_reference && ` | JNL: ${item.journal_reference}`}
                            </span>
                            <span>
                              {item.source_amount} {item.currency_code}
                            </span>
                          </div>
                        ))}
                      </div>
                    )}

                    {proposal.submitted_by && (
                      <div
                        style={{
                          fontSize: '11px',
                          color: 'var(--text-secondary)',
                          marginTop: '8px',
                          borderTop: '1px solid var(--border-default)',
                          paddingTop: '6px',
                        }}
                      >
                        Submitted by: {proposal.submitted_by}
                        {proposal.submitted_at && ` on ${proposal.submitted_at}`}
                      </div>
                    )}

                    {proposal.status === 'CANCELLED' && proposal.cancellation_reason && (
                      <div
                        style={{
                          fontSize: '11px',
                          color: 'var(--critical-red)',
                          marginTop: '4px',
                          fontStyle: 'italic',
                        }}
                      >
                        Cancelled: {proposal.cancellation_reason}
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

PaymentProposalControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
