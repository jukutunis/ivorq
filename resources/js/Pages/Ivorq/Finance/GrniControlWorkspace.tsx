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

interface GrniSource {
  receipt_number: string | null;
  supplier_name: string | null;
  external_reference: string | null;
  grn_number: string | null;
  vendor_delivery_no: string | null;
  vendor_name: string | null;
  vendor_code: string | null;
  po_no: string | null;
}

interface GrniLine {
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

interface GrniItem {
  type: 'candidate' | 'journal';
  id: string;
  candidate_id?: string | null;
  source_type: string;
  source_id: string;
  source: GrniSource | null;
  posting_event: string;
  reference?: string | null;
  description: string | null;
  status: string;
  candidate_date?: string | null;
  transaction_date?: string | null;
  posting_date?: string | null;
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
  lines: GrniLine[];
}

interface Queues {
  pending_review: GrniItem[];
  approved_ready: GrniItem[];
  draft_awaiting_authorization: GrniItem[];
  authorized_ready_to_post: GrniItem[];
  posted_history: GrniItem[];
}

interface Permissions {
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
  { key: 'approved_ready', title: 'Approved — Ready for Journal Draft', color: 'inspection-blue', status: 'inspection' },
  { key: 'draft_awaiting_authorization', title: 'Journal Draft — Awaiting Finalization Authorization', color: 'critical-red', status: 'critical' },
  { key: 'authorized_ready_to_post', title: 'Authorized Draft — Ready to Post', color: 'ready-green', status: 'ready' },
  { key: 'posted_history', title: 'Posted History', color: 'neutral-slate', status: 'neutral' },
];

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/accounts-receivable', label: 'Accounts Receivable' },
  { href: '/finance/budget-watch', label: 'Budget Watch' },
  { href: '/finance/general-ledger/grni-control', label: 'GRNI Control' },
];

function formatAmount(value: number | null | undefined): string {
  return Number(value || 0).toLocaleString(undefined, {
    minimumFractionDigits: 2,
    maximumFractionDigits: 2,
  });
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

function sourceTitle(item: GrniItem): string {
  return item.source?.receipt_number || item.source?.grn_number || item.source_id;
}

function sourceSubtitle(item: GrniItem): string {
  const parts = [
    item.source?.po_no ? `PO ${item.source.po_no}` : null,
    item.source?.vendor_name || item.source?.supplier_name,
    item.source?.vendor_delivery_no ? `Delivery ${item.source.vendor_delivery_no}` : null,
  ].filter(Boolean);

  return parts.length > 0 ? parts.join(' | ') : item.source_type;
}

function itemDate(item: GrniItem): string {
  return formatDate(item.candidate_date || item.transaction_date || item.posting_date);
}

function evidenceActor(item: GrniItem): string {
  return item.posted_by
    || item.draft_finalization_authorized_by
    || item.approved_by
    || item.rejected_by
    || 'N/A';
}

export default function GrniControlWorkspace({ queues, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const allItems = useMemo(() => queueDefinitions.flatMap((definition) => queues[definition.key]), [queues]);
  const [selectedId, setSelectedId] = useState<string | null>(allItems[0]?.id ?? null);
  const [rejectCandidateId, setRejectCandidateId] = useState<string | null>(null);

  const selectedItem = allItems.find((item) => item.id === selectedId) ?? allItems[0] ?? null;

  const actionForm = useForm({});
  const rejectForm = useForm({ rejection_reason: '' });

  const postAction = (name: string, parameters: Record<string, string>) => {
    actionForm.post(route(name, parameters), {
      preserveScroll: true,
    });
  };

  const submitReject = (event: React.FormEvent, candidateId: string) => {
    event.preventDefault();
    rejectForm.post(route('finance.general-ledger.grni-control.candidates.reject', { candidate: candidateId }), {
      preserveScroll: true,
      onSuccess: () => {
        setRejectCandidateId(null);
        rejectForm.reset('rejection_reason');
      },
    });
  };

  const totalOpen = queues.pending_review.length
    + queues.approved_ready.length
    + queues.draft_awaiting_authorization.length
    + queues.authorized_ready_to_post.length;

  return (
    <div className="workspace">
      <WorkspaceHeader title="GRNI Control">
        <Link href={route('finance.general-ledger.grni-control')} preserveScroll className="btn btn-secondary">
          <Icon name="refresh" /> Refresh
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div className="filter-group">
            <label className="filter-label">Open Control Items</label>
            <div style={{ fontSize: '28px', fontWeight: 700, color: 'var(--text-primary)' }}>{totalOpen}</div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Selected Source</label>
            <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
              {selectedItem ? sourceTitle(selectedItem) : 'N/A'}
            </div>
            <div style={{ color: 'var(--text-secondary)', fontSize: '12px', marginTop: '4px' }}>
              {selectedItem ? sourceSubtitle(selectedItem) : 'N/A'}
            </div>
          </div>
          <div className="filter-group">
            <label className="filter-label">Recorded Actor</label>
            <div style={{ fontSize: '13px', fontWeight: 700 }}>{selectedItem ? evidenceActor(selectedItem) : 'N/A'}</div>
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
            <SnapshotCard value={queues.pending_review.length} label="Pending Review" statusColor="warning-amber" />
            <SnapshotCard value={queues.approved_ready.length} label="Ready for Draft" statusColor="inspection-blue" />
            <SnapshotCard value={queues.draft_awaiting_authorization.length} label="Awaiting Authorization" statusColor="critical-red" />
            <SnapshotCard value={queues.authorized_ready_to_post.length} label="Ready to Post" statusColor="ready-green" />
            <SnapshotCard value={queues.posted_history.length} label="Posted History" statusColor="neutral-slate" />
          </OperationalSnapshot>

          {selectedItem && (
            <AttentionArea
              title="Selected GRNI Evidence"
              badgeText={formatLabel(selectedItem.status)}
              badgeType={selectedItem.status === 'Posted' ? 'ready' : 'inspection'}
              areaType="inspection"
            >
              <div
                style={{
                  display: 'grid',
                  gridTemplateColumns: 'repeat(auto-fit, minmax(170px, 1fr))',
                  gap: '10px',
                }}
              >
                <EvidenceCell label="Source" value={sourceTitle(selectedItem)} />
                <EvidenceCell label="GRN" value={selectedItem.source?.grn_number || 'N/A'} />
                <EvidenceCell label="PO" value={selectedItem.source?.po_no || 'N/A'} />
                <EvidenceCell label="Vendor" value={selectedItem.source?.vendor_name || selectedItem.source?.supplier_name || 'N/A'} />
                <EvidenceCell label="Date" value={itemDate(selectedItem)} />
                <EvidenceCell label="Amount" value={formatAmount(selectedItem.amount)} />
              </div>
              <div style={{ marginTop: '12px', display: 'grid', gap: '6px' }}>
                {selectedItem.lines.map((line) => (
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
            </AttentionArea>
          )}

          <BoardHeader title="GRNI Lifecycle Queues" />

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
                            {formatAmount(item.amount)}
                          </span>
                        }
                        actions={
                          <QueueActions
                            item={item}
                            queueKey={definition.key}
                            permissions={permissions}
                            processing={actionForm.processing || rejectForm.processing}
                            rejectCandidateId={rejectCandidateId}
                            setSelectedId={setSelectedId}
                            setRejectCandidateId={setRejectCandidateId}
                            postAction={postAction}
                          />
                        }
                      />

                      {rejectCandidateId === item.id && (
                        <form
                          onSubmit={(event) => submitReject(event, item.id)}
                          style={{
                            marginTop: '8px',
                            border: '1px solid var(--border-default)',
                            borderRadius: '6px',
                            padding: '8px',
                            background: 'var(--surface-card)',
                          }}
                        >
                          <textarea
                            value={rejectForm.data.rejection_reason}
                            onChange={(event) => rejectForm.setData('rejection_reason', event.target.value)}
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
                          {rejectForm.errors.rejection_reason && (
                            <div style={{ color: 'var(--critical-red)', fontSize: '12px', marginTop: '4px' }}>
                              {rejectForm.errors.rejection_reason}
                            </div>
                          )}
                          <div style={{ display: 'flex', gap: '6px', marginTop: '8px' }}>
                            <Button
                              type="button"
                              variant="secondary"
                              size="sm"
                              onClick={() => {
                                setRejectCandidateId(null);
                                rejectForm.reset('rejection_reason');
                              }}
                            >
                              Cancel
                            </Button>
                            <Button type="submit" size="sm" disabled={rejectForm.processing}>
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

function EvidenceCell({ label, value }: { label: string; value: string }) {
  return (
    <div>
      <div style={{ color: 'var(--text-secondary)', fontSize: '11px', fontWeight: 700, textTransform: 'uppercase' }}>{label}</div>
      <div style={{ color: 'var(--text-primary)', fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>{value}</div>
    </div>
  );
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
}: {
  item: GrniItem;
  queueKey: QueueKey;
  permissions: Permissions;
  processing: boolean;
  rejectCandidateId: string | null;
  setSelectedId: (id: string) => void;
  setRejectCandidateId: (id: string | null) => void;
  postAction: (name: string, parameters: Record<string, string>) => void;
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
            onClick={() => postAction('finance.general-ledger.grni-control.candidates.approve', { candidate: item.id })}
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
          onClick={() => postAction('finance.general-ledger.grni-control.candidates.materialize', { candidate: item.id })}
        >
          <Icon name="finance" /> Draft
        </Button>
      )}

      {queueKey === 'draft_awaiting_authorization' && permissions.can_authorize && (
        <Button
          type="button"
          size="sm"
          disabled={processing}
          onClick={() => postAction('finance.general-ledger.grni-control.journals.authorize-finalization', { journalEntry: item.id })}
        >
          <Icon name="finance" /> Authorize
        </Button>
      )}

      {queueKey === 'authorized_ready_to_post' && permissions.can_post && (
        <Button
          type="button"
          size="sm"
          disabled={processing}
          onClick={() => postAction('finance.general-ledger.grni-control.journals.post', { journalEntry: item.id })}
        >
          <Icon name="finance" /> Post
        </Button>
      )}
    </>
  );
}

GrniControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
