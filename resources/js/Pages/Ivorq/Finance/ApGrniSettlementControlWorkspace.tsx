import React, { useMemo, useState } from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
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
import BoardHeader from '../../../Components/Ivorq/housekeeping/BoardHeader';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge, { BadgeStatus } from '../../../Components/Ivorq/primitives/StatusBadge';

declare const route: any;

interface VendorEvidence {
  name: string | null;
  code: string | null;
}

interface JournalEvidence {
  reference: string | null;
  status: string;
  transaction_date: string | null;
  posting_date: string | null;
  posted_by: string | null;
  posted_at: string | null;
  finalized_by: string | null;
  finalized_at: string | null;
}

interface CandidateEvidence {
  status: string;
  approved_by: string | null;
  approved_at: string | null;
  rejected_by: string | null;
  rejected_at: string | null;
  rejection_reason: string | null;
  source_grni_candidate_id: string | null;
  source_grni_journal_entry_id: string | null;
}

interface AgeEvidence {
  available: boolean;
  days: number | null;
  posted_business_date: string | null;
  current_business_date: string | null;
  label: string;
}

interface SourceEvidence {
  purchase_order?: { id?: string | null; line_id?: string | null } | null;
  receiving?: { document_id?: string | null; line_id?: string | null; inventory_receipt_line_id?: string | null } | null;
  source_grni?: { candidate_id?: string | null; journal_entry_id?: string | null; amount?: string | null } | null;
}

interface SettlementItem {
  id: string;
  type: string;
  settlement_status: string;
  invoice_number: string | null;
  vendor: VendorEvidence | null;
  property: string | null;
  currency_code: string | null;
  amount: string | null;
  journal: JournalEvidence | null;
  candidate: CandidateEvidence | null;
  source: SourceEvidence | null;
  age?: AgeEvidence;
  reason: string | null;
}

interface Projection {
  current_business_date: string | null;
  queues: {
    ready: SettlementItem[];
    aging: SettlementItem[];
    history: SettlementItem[];
    held: SettlementItem[];
  };
  summary: {
    ready_count: number;
    aging_count: number;
    history_count: number;
    held_count: number;
  };
}

interface Props {
  projection: Projection;
  payment_proposals: PaymentProposal[];
  permissions: {
    can_view: boolean;
    can_create_payment_proposal: boolean;
    can_cancel_payment_proposal: boolean;
  };
}

interface PaymentProposal {
  id: string;
  proposal_number: string;
  vendor: string | null;
  currency_code: string;
  status: string;
  total_amount: string;
  created_at: string | null;
  items: Array<{
    id: string;
    invoice_number: string | null;
    source_journal_entry_id: string;
    source_amount: string;
  }>;
}

type QueueKey = keyof Projection['queues'];

const queueDefinitions: Array<{ key: QueueKey; title: string; color: string; status: BadgeStatus }> = [
  { key: 'ready', title: 'Ready for Payment Proposal', color: 'ready-green', status: 'ready' },
  { key: 'aging', title: 'Posted AP Liability Aging', color: 'inspection-blue', status: 'inspection' },
  { key: 'history', title: 'GRNI/AP Lifecycle History', color: 'neutral-slate', status: 'neutral' },
  { key: 'held', title: 'Held or Blocked Conditions', color: 'warning-amber', status: 'warning' },
];

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/ap-grni-settlement-control', label: 'AP / GRNI Settlement' },
  { href: '/finance/accounts-receivable', label: 'Accounts Receivable' },
  { href: '/finance/budget-watch', label: 'Budget Watch' },
  { href: '/finance/general-ledger/grni-control', label: 'GRNI Control' },
];

function formatAmount(value: string | number | null | undefined, currency?: string | null): string {
  const amount = Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return currency ? `${currency} ${amount}` : amount;
}

function formatDate(value: string | null | undefined): string {
  return value ? value.slice(0, 10) : 'N/A';
}

function formatLabel(value: string | null | undefined): string {
  if (!value) return 'N/A';
  return value
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .toLowerCase()
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function itemTitle(item: SettlementItem): string {
  return item.invoice_number || item.journal?.reference || 'Unreferenced source';
}

function itemSubtitle(item: SettlementItem): string {
  const parts = [
    item.vendor?.name,
    item.property,
    item.age?.label,
  ].filter(Boolean);

  return parts.length > 0 ? parts.join(' | ') : item.settlement_status;
}

export default function ApGrniSettlementControlWorkspace({ projection, payment_proposals, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const allItems = useMemo(() => queueDefinitions.flatMap((definition) => projection.queues[definition.key]), [projection]);
  const [selectedId, setSelectedId] = useState<string | null>(allItems[0]?.id ?? null);
  const [cancelProposalId, setCancelProposalId] = useState<string | null>(null);
  const selectedItem = allItems.find((item) => item.id === selectedId) ?? allItems[0] ?? null;
  const createForm = useForm<{ journal_entry_ids: string[] }>({ journal_entry_ids: [] });
  const cancelForm = useForm({ cancellation_reason: '' });

  const createDraft = (journalEntryId: string) => {
    createForm.setData('journal_entry_ids', [journalEntryId]);
    createForm.post(route('finance.payables.ap-grni-settlement-control.payment-proposals.create'), {
      preserveScroll: true,
    });
  };

  const submitCancel = (event: React.FormEvent, proposalId: string) => {
    event.preventDefault();
    cancelForm.post(route('finance.payables.ap-grni-settlement-control.payment-proposals.cancel', { paymentProposal: proposalId }), {
      preserveScroll: true,
      onSuccess: () => {
        setCancelProposalId(null);
        cancelForm.reset('cancellation_reason');
      },
    });
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title="AP / GRNI Settlement Control">
        <Link href={route('finance.payables.ap-grni-settlement-control')} preserveScroll className="btn btn-secondary">
          <Icon name="refresh" /> Refresh
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div className="filter-group">
            <label className="filter-label">Current Business Date</label>
            <div style={{ fontSize: '20px', fontWeight: 700, color: 'var(--text-primary)' }}>
              {projection.current_business_date || 'Unavailable'}
            </div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Ready Sources</label>
            <div style={{ fontSize: '28px', fontWeight: 700, color: 'var(--ready-green)' }}>
              {projection.summary.ready_count}
            </div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Selected Invoice</label>
            <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
              {selectedItem ? itemTitle(selectedItem) : 'N/A'}
            </div>
            <div style={{ color: 'var(--text-secondary)', fontSize: '12px', marginTop: '4px' }}>
              {selectedItem ? itemSubtitle(selectedItem) : 'N/A'}
            </div>
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

          <OperationalSnapshot>
            <SnapshotCard value={projection.summary.ready_count} label="Ready" statusColor="ready-green" />
            <SnapshotCard value={projection.summary.aging_count} label="Aging" statusColor="inspection-blue" />
            <SnapshotCard value={projection.summary.history_count} label="Lifecycle" statusColor="neutral-slate" />
            <SnapshotCard value={projection.summary.held_count} label="Held" statusColor="warning-amber" />
            <SnapshotCard value={payment_proposals.length} label="Draft Proposals" statusColor="inspection-blue" />
          </OperationalSnapshot>

          {selectedItem && (
            <AttentionArea
              title="Source Evidence"
              badgeText={formatLabel(selectedItem.settlement_status)}
              badgeType={selectedItem.reason ? 'warning' : 'ready'}
              areaType={selectedItem.reason ? 'warning' : 'inspection'}
            >
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
                  gap: '10px',
                }}
              >
                <EvidenceCell label="Invoice" value={itemTitle(selectedItem)} />
                <EvidenceCell label="Vendor" value={selectedItem.vendor?.name || 'N/A'} />
                <EvidenceCell label="Amount" value={formatAmount(selectedItem.amount, selectedItem.currency_code)} />
                <EvidenceCell label="Journal Date" value={formatDate(selectedItem.journal?.transaction_date)} />
                <EvidenceCell label="Posted" value={formatDate(selectedItem.journal?.posting_date)} />
                <EvidenceCell label="Age" value={selectedItem.age?.label || selectedItem.reason || 'Age unavailable.'} />
              </div>

              <div style={{ marginTop: '12px', display: 'grid', gap: '6px', fontSize: '12px' }}>
                <EvidenceRow label="AP Journal" value={selectedItem.journal?.reference || selectedItem.journal?.status || 'N/A'} />
                <EvidenceRow label="GRNI Candidate" value={selectedItem.candidate?.source_grni_candidate_id || 'N/A'} />
                <EvidenceRow label="GRNI Journal" value={selectedItem.candidate?.source_grni_journal_entry_id || 'N/A'} />
                <EvidenceRow label="Receiving" value={selectedItem.source?.receiving?.document_id || 'N/A'} />
                <EvidenceRow label="Purchase Order" value={selectedItem.source?.purchase_order?.id || 'N/A'} />
                {selectedItem.reason && <EvidenceRow label="Held Reason" value={selectedItem.reason} />}
              </div>
            </AttentionArea>
          )}

          <BoardHeader title="Settlement Control Queues" />

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(240px, 1fr))',
              gap: '12px',
              alignItems: 'start',
            }}
          >
            {queueDefinitions.map((definition) => (
              <QueueList
                key={definition.key}
                title={<span>{definition.title}</span>}
                count={projection.queues[definition.key].length}
                headerStyle={{ minHeight: '48px' }}
              >
                <div style={{ display: 'grid', gap: '10px', padding: '10px' }}>
                  {projection.queues[definition.key].length === 0 && (
                    <div style={{ color: 'var(--text-secondary)', fontSize: '12px', padding: '12px 4px' }}>
                      No records.
                    </div>
                  )}

                  {projection.queues[definition.key].map((item) => (
                    <WorkCard
                      key={item.id}
                      borderColor={definition.color}
                      meta={
                        <>
                          <span>{formatDate(item.journal?.transaction_date)}</span>
                          <StatusBadge status={definition.status}>{formatLabel(item.settlement_status)}</StatusBadge>
                        </>
                      }
                      title={itemTitle(item)}
                      detail={
                        <span>
                          {itemSubtitle(item)}
                          <br />
                          {formatAmount(item.amount, item.currency_code)}
                        </span>
                      }
                      actions={
                        <>
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedId(item.id)}>
                            <Icon name="search" /> Details
                          </Button>
                          {definition.key === 'ready' && permissions.can_create_payment_proposal && item.journal && (
                            <Button
                              type="button"
                              size="sm"
                              disabled={createForm.processing}
                              onClick={() => createDraft(item.id)}
                            >
                              <Icon name="finance" /> Draft
                            </Button>
                          )}
                        </>
                      }
                    />
                  ))}
                </div>
              </QueueList>
            ))}
          </div>

          <BoardHeader title="Draft Payment Proposals" />

          <QueueList title={<span>Payment Proposal Drafts</span>} count={payment_proposals.length} headerStyle={{ minHeight: '48px' }}>
            <div style={{ display: 'grid', gap: '10px', padding: '10px' }}>
              {payment_proposals.length === 0 && (
                <div style={{ color: 'var(--text-secondary)', fontSize: '12px', padding: '12px 4px' }}>
                  No draft proposals.
                </div>
              )}

              {payment_proposals.map((proposal) => (
                <div key={proposal.id}>
                  <WorkCard
                    borderColor="inspection-blue"
                    meta={
                      <>
                        <span>{formatDate(proposal.created_at)}</span>
                        <StatusBadge status="inspection">{formatLabel(proposal.status)}</StatusBadge>
                      </>
                    }
                    title={proposal.proposal_number}
                    detail={
                      <span>
                        {proposal.vendor || 'Vendor unavailable'}
                        <br />
                        {formatAmount(proposal.total_amount, proposal.currency_code)}
                      </span>
                    }
                    actions={
                      permissions.can_cancel_payment_proposal && (
                        <Button
                          type="button"
                          size="sm"
                          variant="secondary"
                          disabled={cancelForm.processing}
                          onClick={() => setCancelProposalId(cancelProposalId === proposal.id ? null : proposal.id)}
                        >
                          <Icon name="warning" /> Cancel
                        </Button>
                      )
                    }
                  />

                  {cancelProposalId === proposal.id && (
                    <form
                      onSubmit={(event) => submitCancel(event, proposal.id)}
                      style={{
                        marginTop: '8px',
                        border: '1px solid var(--border-default)',
                        borderRadius: '6px',
                        padding: '8px',
                        background: 'var(--surface-card)',
                      }}
                    >
                      <textarea
                        value={cancelForm.data.cancellation_reason}
                        onChange={(event) => cancelForm.setData('cancellation_reason', event.target.value)}
                        rows={3}
                        required
                        placeholder="Cancellation reason"
                        style={{
                          width: '100%',
                          resize: 'vertical',
                          border: '1px solid var(--border-default)',
                          borderRadius: '6px',
                          padding: '8px',
                          fontSize: '12px',
                        }}
                      />
                      <div style={{ display: 'flex', gap: '6px', marginTop: '8px' }}>
                        <Button
                          type="button"
                          variant="secondary"
                          size="sm"
                          onClick={() => {
                            setCancelProposalId(null);
                            cancelForm.reset('cancellation_reason');
                          }}
                        >
                          Back
                        </Button>
                        <Button type="submit" size="sm" disabled={cancelForm.processing}>
                          <Icon name="warning" /> Cancel Draft
                        </Button>
                      </div>
                    </form>
                  )}
                </div>
              ))}
            </div>
          </QueueList>
        </MainContent>
      </SplitLayout>
    </div>
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
    <div style={{ display: 'grid', gridTemplateColumns: '130px minmax(0, 1fr)', gap: '8px' }}>
      <span style={{ color: 'var(--text-secondary)', fontWeight: 700 }}>{label}</span>
      <span style={{ color: 'var(--text-primary)', wordBreak: 'break-word' }}>{value}</span>
    </div>
  );
}

ApGrniSettlementControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
