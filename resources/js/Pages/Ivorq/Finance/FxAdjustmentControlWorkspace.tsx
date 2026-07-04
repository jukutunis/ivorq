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

interface FxSource {
  allocation_id: string;
  allocation_amount: number;
  currency: string;
  allocated_by: string | null;
  allocated_at: string | null;
  invoice_number: string | null;
  invoice_grand_total: number | null;
  payment_execution_id: string | null;
  payment_ref: string | null;
  payment_date: string | null;
  ap_journal_entry_id: string | null;
  ap_journal_reference: string | null;
  payment_journal_entry_id: string | null;
  payment_journal_reference: string | null;
}

interface FxRateEvidence {
  id: string | null;
  base_currency: string | null;
  quote_currency: string | null;
  rate: string | null;
  effective_date: string | null;
  status: string | null;
}

interface FxMappingSnapshot {
  id: string | null;
  operational_identity: string | null;
  account_id: string | null;
  is_active: boolean | null;
}

interface FxLine {
  id: string;
  identity?: string | null;
  entry_type?: string | null;
  amount?: number | null;
  account_code?: string | null;
  account_name?: string | null;
  debit_amount?: number | null;
  credit_amount?: number | null;
  notes?: string | null;
  memo?: string | null;
}

interface FxItem {
  type: 'candidate' | 'journal';
  id: string;
  candidate_id?: string | null;
  source_type: string;
  source_id: string;
  source: FxSource | null;
  posting_event: string;
  reference?: string | null;
  description: string | null;
  status: string;
  candidate_date?: string | null;
  transaction_date?: string | null;
  posting_date?: string | null;
  created_by?: string | null;
  approved_by?: string | null;
  approved_at?: string | null;
  draft_finalization_authorized_by?: string | null;
  draft_finalization_authorized_at?: string | null;
  posted_by?: string | null;
  posted_at?: string | null;
  rejected_by?: string | null;
  rejected_at?: string | null;
  rejection_reason?: string | null;
  debit_total: number;
  credit_total: number;
  amount: number;
  realized_direction: string;
  rate?: string | null;
  rate_evidence: FxRateEvidence | null;
  mapping_summary: {
    fx_gain: FxMappingSnapshot | null;
    fx_loss: FxMappingSnapshot | null;
  };
  lines: FxLine[];
}

interface Queues {
  pending_review: FxItem[];
  approved_ready: FxItem[];
  draft_awaiting_authorization: FxItem[];
  authorized_ready_to_post: FxItem[];
  posted_history: FxItem[];
}

interface Permissions {
  can_create: boolean;
  can_review: boolean;
  can_materialize: boolean;
  can_authorize: boolean;
  can_post: boolean;
}

interface Props {
  queues: Queues;
  permissions: Permissions;
}

type QueueKey = keyof Queues;

const queueDefinitions: Array<{ key: QueueKey; title: string; color: string; status: BadgeStatus }> = [
  { key: 'pending_review', title: 'Pending Review', color: 'warning-amber', status: 'warning' },
  { key: 'approved_ready', title: 'Approved - Ready for Draft', color: 'inspection-blue', status: 'inspection' },
  { key: 'draft_awaiting_authorization', title: 'Awaiting Finalization Authorization', color: 'critical-red', status: 'critical' },
  { key: 'authorized_ready_to_post', title: 'Ready to Post', color: 'ready-green', status: 'ready' },
  { key: 'posted_history', title: 'Posted History', color: 'neutral-slate', status: 'neutral' },
];

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/ap-grni-settlement-control', label: 'AP / GRNI Settlement' },
  { href: '/finance/accounts-receivable', label: 'Accounts Receivable' },
  { href: '/finance/budget-watch', label: 'Budget Watch' },
  { href: '/finance/general-ledger/grni-control', label: 'GRNI Control' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

function formatAmount(value: number | null | undefined, currency?: string | null): string {
  const formatted = Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });

  return currency ? `${currency} ${formatted}` : formatted;
}

function formatDate(value: string | null | undefined): string {
  if (!value) return 'N/A';

  return value.slice(0, 10);
}

function formatLabel(value: string | null | undefined): string {
  if (!value) return 'N/A';

  return value
    .replace(/_/g, ' ')
    .replace(/([a-z])([A-Z])/g, '$1 $2')
    .toLowerCase()
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

function sourceTitle(item: FxItem): string {
  return item.source?.invoice_number ? `Invoice ${item.source.invoice_number}` : `Allocation ${item.source_id}`;
}

function sourceSubtitle(item: FxItem): string {
  const parts = [
    item.source?.payment_ref ? `Payment ${item.source.payment_ref}` : null,
    item.source?.currency ? `Currency ${item.source.currency}` : null,
    item.realized_direction ? `Direction ${formatLabel(item.realized_direction)}` : null,
    item.rate ? `Rate ${item.rate}` : null,
  ].filter(Boolean);

  return parts.length > 0 ? parts.join(' | ') : item.source_type;
}

function itemDate(item: FxItem): string {
  return formatDate(item.candidate_date || item.transaction_date || item.posting_date);
}

function evidenceActor(item: FxItem): string {
  return item.posted_by
    || item.draft_finalization_authorized_by
    || item.approved_by
    || item.rejected_by
    || item.created_by
    || 'N/A';
}

function hasLifecycleAuthority(permissions: Permissions): boolean {
  return permissions.can_create
    || permissions.can_review
    || permissions.can_materialize
    || permissions.can_authorize
    || permissions.can_post;
}

export default function FxAdjustmentControlWorkspace({ queues, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const allItems = useMemo(() => queueDefinitions.flatMap((definition) => queues[definition.key]), [queues]);

  const [selectedId, setSelectedId] = useState<string | null>(allItems[0]?.id ?? null);
  const [rejectCandidateId, setRejectCandidateId] = useState<string | null>(null);

  const selectedItem = allItems.find((item) => item.id === selectedId) ?? allItems[0] ?? null;

  const createForm = useForm({ allocation_id: '' });
  const actionForm = useForm({});
  const reviewForm = useForm({ rejection_reason: '' });

  const handleCreate = (event: React.FormEvent) => {
    event.preventDefault();
    createForm.post(route('finance.fx-adjustments.candidates.create'), {
      preserveScroll: true,
      onSuccess: () => createForm.reset('allocation_id'),
    });
  };

  const approveCandidate = (candidateId: string) => {
    reviewForm.transform(() => ({
      decision: 'APPROVED',
      rejection_reason: '',
    })).post(route('finance.fx-adjustments.candidates.review', { candidate: candidateId }), {
      preserveScroll: true,
      onSuccess: () => {
        setRejectCandidateId(null);
        reviewForm.reset('rejection_reason');
      },
    });
  };

  const rejectCandidate = (event: React.FormEvent, candidateId: string) => {
    event.preventDefault();
    reviewForm.transform((data) => ({
      decision: 'REJECTED',
      rejection_reason: data.rejection_reason,
    })).post(route('finance.fx-adjustments.candidates.review', { candidate: candidateId }), {
      preserveScroll: true,
      onSuccess: () => {
        setRejectCandidateId(null);
        reviewForm.reset('rejection_reason');
      },
    });
  };

  const postAction = (name: string, parameters: Record<string, string>) => {
    actionForm.post(route(name, parameters), {
      preserveScroll: true,
    });
  };

  const totalOpen = queues.pending_review.length
    + queues.approved_ready.length
    + queues.draft_awaiting_authorization.length
    + queues.authorized_ready_to_post.length;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / FX Adjustments">
        <Link href={route('finance.fx-adjustments.index')} preserveScroll className="btn btn-secondary">
          <Icon name="refresh" /> Refresh
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div className="filter-group">
            <label className="filter-label">Current Property Open Items</label>
            <div style={{ fontSize: '28px', fontWeight: 700, color: 'var(--text-primary)' }}>{totalOpen}</div>
          </div>

          <div className="filter-group">
            <label className="filter-label">Lifecycle State</label>
            <div style={{ fontSize: '13px', fontWeight: 700 }}>
              {selectedItem ? formatLabel(selectedItem.status) : 'No realized FX items'}
            </div>
            <div style={{ color: 'var(--text-secondary)', fontSize: '12px', marginTop: '4px' }}>
              {selectedItem ? sourceSubtitle(selectedItem) : 'Current property has no projected realized-FX exposure.'}
            </div>
          </div>

          <div className="filter-group">
            <label className="filter-label">Recorded Actor</label>
            <div style={{ fontSize: '13px', fontWeight: 700 }}>{selectedItem ? evidenceActor(selectedItem) : 'N/A'}</div>
          </div>

          {!hasLifecycleAuthority(permissions) && (
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <div style={{ color: 'var(--text-secondary)', fontSize: '12px', lineHeight: 1.4 }}>
                View authority is active. Lifecycle actions require separate Finance permissions.
              </div>
            </div>
          )}

          {permissions.can_create && (
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <label className="filter-label" style={{ marginBottom: '8px' }}>Create Candidate</label>
              <form onSubmit={handleCreate} style={{ display: 'flex', flexDirection: 'column', gap: '8px' }}>
                <input
                  type="text"
                  placeholder="AP settlement allocation ULID"
                  value={createForm.data.allocation_id}
                  onChange={(event) => createForm.setData('allocation_id', event.target.value)}
                  style={{
                    padding: '6px 8px',
                    fontSize: '12px',
                    border: '1px solid var(--border-default)',
                    borderRadius: '4px',
                    backgroundColor: 'var(--surface-input)',
                    color: 'var(--text-primary)',
                  }}
                  required
                />
                {createForm.errors.allocation_id && (
                  <div style={{ color: 'var(--critical-red)', fontSize: '11px' }}>
                    {createForm.errors.allocation_id}
                  </div>
                )}
                <Button type="submit" size="sm" disabled={createForm.processing}>
                  <Icon name="plus" /> Create
                </Button>
              </form>
            </div>
          )}
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
            <SnapshotCard value={queues.pending_review.length} label="Pending Review" statusColor="warning-amber" />
            <SnapshotCard value={queues.approved_ready.length} label="Ready for Draft" statusColor="inspection-blue" />
            <SnapshotCard value={queues.draft_awaiting_authorization.length} label="Awaiting Auth" statusColor="critical-red" />
            <SnapshotCard value={queues.authorized_ready_to_post.length} label="Ready to Post" statusColor="ready-green" />
            <SnapshotCard value={queues.posted_history.length} label="Posted History" statusColor="neutral-slate" />
          </OperationalSnapshot>

          {selectedItem ? (
            <SelectedEvidence item={selectedItem} />
          ) : (
            <AttentionArea title="Realized FX Exposure" badgeText="No Data" badgeType="neutral" areaType="inspection">
              <div style={{ color: 'var(--text-secondary)', fontSize: '13px' }}>
                No realized-FX candidate, draft, authorization, or posted history is projected for the current property.
              </div>
            </AttentionArea>
          )}

          <BoardHeader title="Realized FX Lifecycle Queues" />

          <div
            style={{
              display: 'grid',
              gridTemplateColumns: 'repeat(auto-fit, minmax(230px, 1fr))',
              gap: '12px',
              alignItems: 'start',
            }}
          >
            {queueDefinitions.map((definition) => (
              <QueueList
                key={definition.key}
                title={<span>{definition.title}</span>}
                count={queues[definition.key].length}
                headerStyle={{ minHeight: '48px' }}
              >
                <div style={{ display: 'grid', gap: '10px', padding: '10px' }}>
                  {queues[definition.key].length === 0 && (
                    <div style={{ color: 'var(--text-secondary)', fontSize: '12px', padding: '12px 4px' }}>
                      No records.
                    </div>
                  )}

                  {queues[definition.key].map((item) => (
                    <div key={item.id}>
                      <WorkCard
                        borderColor={definition.color}
                        meta={
                          <>
                            <span>{itemDate(item)}</span>
                            <StatusBadge status={definition.status}>{formatLabel(item.status)}</StatusBadge>
                          </>
                        }
                        title={sourceTitle(item)}
                        detail={
                          <span>
                            {sourceSubtitle(item)}
                            <br />
                            Realized: {formatAmount(item.amount)}
                          </span>
                        }
                        actions={
                          <QueueActions
                            item={item}
                            queueKey={definition.key}
                            permissions={permissions}
                            processing={actionForm.processing || reviewForm.processing}
                            rejectCandidateId={rejectCandidateId}
                            setSelectedId={setSelectedId}
                            setRejectCandidateId={setRejectCandidateId}
                            postAction={postAction}
                            approveCandidate={approveCandidate}
                          />
                        }
                      />

                      {rejectCandidateId === item.id && (
                        <form
                          onSubmit={(event) => rejectCandidate(event, item.id)}
                          style={{
                            marginTop: '8px',
                            border: '1px solid var(--border-default)',
                            borderRadius: '6px',
                            padding: '8px',
                            background: 'var(--surface-card)',
                          }}
                        >
                          <textarea
                            value={reviewForm.data.rejection_reason}
                            onChange={(event) => reviewForm.setData('rejection_reason', event.target.value)}
                            rows={3}
                            required
                            placeholder="Rejection reason"
                            style={{
                              width: '100%',
                              resize: 'vertical',
                              border: '1px solid var(--border-default)',
                              borderRadius: '6px',
                              padding: '8px',
                              fontSize: '12px',
                            }}
                          />
                          {reviewForm.errors.rejection_reason && (
                            <div style={{ color: 'var(--critical-red)', fontSize: '12px', marginTop: '4px' }}>
                              {reviewForm.errors.rejection_reason}
                            </div>
                          )}
                          <div style={{ display: 'flex', gap: '6px', marginTop: '8px' }}>
                            <Button
                              type="button"
                              variant="secondary"
                              size="sm"
                              onClick={() => {
                                setRejectCandidateId(null);
                                reviewForm.reset('rejection_reason');
                              }}
                            >
                              Cancel
                            </Button>
                            <Button type="submit" size="sm" disabled={reviewForm.processing}>
                              <Icon name="warning" /> Reject
                            </Button>
                          </div>
                        </form>
                      )}
                    </div>
                  ))}
                </div>
              </QueueList>
            ))}
          </div>
        </MainContent>
      </SplitLayout>
    </div>
  );
}

function SelectedEvidence({ item }: { item: FxItem }) {
  return (
    <AttentionArea
      title="Selected Candidate Evidence"
      badgeText={formatLabel(item.status)}
      badgeType={item.status === 'Posted' ? 'ready' : 'inspection'}
      areaType="inspection"
    >
      <EvidenceGrid>
        <EvidenceCell label="Candidate Reference" value={item.candidate_id || item.id} />
        <EvidenceCell label="Supplier Invoice" value={item.source?.invoice_number || 'N/A'} />
        <EvidenceCell label="Payment Execution" value={item.source?.payment_execution_id || 'N/A'} />
        <EvidenceCell label="Payment Date" value={formatDate(item.source?.payment_date)} />
        <EvidenceCell label="Allocation Reference" value={item.source?.allocation_id || item.source_id} />
        <EvidenceCell label="Approved Rate Evidence" value={item.rate_evidence?.id || 'N/A'} />
        <EvidenceCell label="Rate Summary" value={rateSummary(item.rate_evidence)} />
        <EvidenceCell label="Realized Direction" value={formatLabel(item.realized_direction)} />
        <EvidenceCell label="Realized Amount" value={formatAmount(item.amount)} />
        <EvidenceCell label="AP Source Journal" value={item.source?.ap_journal_reference || item.source?.ap_journal_entry_id || 'N/A'} />
        <EvidenceCell label="Payment Source Journal" value={item.source?.payment_journal_reference || item.source?.payment_journal_entry_id || 'N/A'} />
        <EvidenceCell label="Lifecycle Actor" value={evidenceActor(item)} />
      </EvidenceGrid>

      <div style={{ marginTop: '16px' }}>
        <SectionLabel>Mapping / Account Summary</SectionLabel>
        <EvidenceGrid>
          <EvidenceCell label="FX Gain Mapping" value={mappingSummary(item.mapping_summary.fx_gain)} />
          <EvidenceCell label="FX Loss Mapping" value={mappingSummary(item.mapping_summary.fx_loss)} />
        </EvidenceGrid>
      </div>

      <div style={{ marginTop: '16px' }}>
        <SectionLabel>Candidate Debit / Credit Lines</SectionLabel>
        <div style={{ display: 'grid', gap: '6px' }}>
          {item.lines.map((line) => (
            <div
              key={line.id}
              style={{
                display: 'grid',
                gridTemplateColumns: 'minmax(120px, 1.3fr) minmax(80px, .7fr) minmax(80px, .7fr)',
                gap: '8px',
                alignItems: 'center',
                fontSize: '12px',
                borderTop: '1px solid var(--border-default)',
                paddingTop: '6px',
              }}
            >
              <div style={{ fontWeight: 700 }}>
                {line.account_code ? `${line.account_code} ${line.account_name || ''}` : formatLabel(line.identity)}
              </div>
              <div>Dr {formatAmount(line.debit_amount ?? (line.entry_type === 'DEBIT' ? line.amount : 0))}</div>
              <div>Cr {formatAmount(line.credit_amount ?? (line.entry_type === 'CREDIT' ? line.amount : 0))}</div>
            </div>
          ))}
        </div>
      </div>
    </AttentionArea>
  );
}

function EvidenceGrid({ children }: { children: React.ReactNode }) {
  return (
    <div
      style={{
        display: 'grid',
        gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
        gap: '10px',
      }}
    >
      {children}
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

function SectionLabel({ children }: { children: React.ReactNode }) {
  return (
    <div style={{ fontSize: '11px', fontWeight: 700, color: 'var(--text-secondary)', textTransform: 'uppercase', marginBottom: '8px' }}>
      {children}
    </div>
  );
}

function rateSummary(rateEvidence: FxRateEvidence | null): string {
  if (!rateEvidence) return 'N/A';

  return [
    rateEvidence.base_currency && rateEvidence.quote_currency
      ? `${rateEvidence.base_currency}/${rateEvidence.quote_currency}`
      : null,
    rateEvidence.rate,
    formatDate(rateEvidence.effective_date),
    formatLabel(rateEvidence.status),
  ].filter(Boolean).join(' | ');
}

function mappingSummary(mapping: FxMappingSnapshot | null): string {
  if (!mapping) return 'N/A';

  return [
    formatLabel(mapping.operational_identity),
    mapping.account_id,
    mapping.is_active === null ? null : (mapping.is_active ? 'Active' : 'Inactive'),
  ].filter(Boolean).join(' | ');
}

function QueueActions({
  item,
  queueKey,
  permissions,
  processing,
  rejectCandidateId,
  setSelectedId,
  setRejectCandidateId,
  postAction,
  approveCandidate,
}: {
  item: FxItem;
  queueKey: QueueKey;
  permissions: Permissions;
  processing: boolean;
  rejectCandidateId: string | null;
  setSelectedId: (id: string) => void;
  setRejectCandidateId: (id: string | null) => void;
  postAction: (name: string, parameters: Record<string, string>) => void;
  approveCandidate: (candidateId: string) => void;
}) {
  return (
    <>
      <Button type="button" size="sm" variant="secondary" onClick={() => setSelectedId(item.id)}>
        <Icon name="search" /> Details
      </Button>

      {queueKey === 'pending_review' && permissions.can_review && (
        <>
          <Button
            type="button"
            size="sm"
            disabled={processing}
            onClick={() => approveCandidate(item.id)}
          >
            <Icon name="finance" /> Approve
          </Button>
          <Button
            type="button"
            size="sm"
            variant="secondary"
            disabled={processing}
            onClick={() => setRejectCandidateId(rejectCandidateId === item.id ? null : item.id)}
          >
            <Icon name="warning" /> Reject
          </Button>
        </>
      )}

      {queueKey === 'approved_ready' && permissions.can_materialize && (
        <Button
          type="button"
          size="sm"
          disabled={processing}
          onClick={() => postAction('finance.fx-adjustments.candidates.materialize', { candidate: item.id })}
        >
          <Icon name="finance" /> Draft
        </Button>
      )}

      {queueKey === 'draft_awaiting_authorization' && permissions.can_authorize && (
        <Button
          type="button"
          size="sm"
          disabled={processing}
          onClick={() => postAction('finance.fx-adjustments.journals.authorize-finalization', { journalEntry: item.id })}
        >
          <Icon name="finance" /> Authorize
        </Button>
      )}

      {queueKey === 'authorized_ready_to_post' && permissions.can_post && (
        <Button
          type="button"
          size="sm"
          disabled={processing}
          onClick={() => postAction('finance.fx-adjustments.journals.post', { journalEntry: item.id })}
        >
          <Icon name="finance" /> Post
        </Button>
      )}
    </>
  );
}

FxAdjustmentControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
