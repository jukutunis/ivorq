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
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';

declare const route: any;

interface Transaction {
  id: string;
  direction: string | null;
  amount: string;
  currency_code: string;
  posted_business_date: string | null;
  journal_reference: string | null;
  created_at: string | null;
}

interface Proposal {
  id: string;
  proposal_number: string;
  vendor_name: string | null;
  total_amount: string;
  currency_code: string;
  approved_at: string | null;
}

interface CashEligibleItem {
  id: string;
  proposal_number: string | null;
  invoice_number: string | null;
  amount: string;
  currency_code: string;
  vendor_id: string | null;
}

interface CashSession {
  id: string;
  status: string;
  opened_at: string | null;
}

interface CashInstrument {
  id: string;
  name: string;
  type: string;
}

interface CashExecutionContext {
  eligible_items: CashEligibleItem[];
  cash_sessions: CashSession[];
  cash_instruments: CashInstrument[];
}

interface Props {
  transactions: Transaction[];
  approved_proposals: Proposal[];
  cash_execution_context: CashExecutionContext;
}

type Selection =
  | { type: 'proposal'; id: string }
  | { type: 'transaction'; id: string };

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

function transactionLabel(transaction: Transaction): string {
  return transaction.direction === 'OUTFLOW' ? 'Payment Outflow' : (transaction.direction || 'Cash Transaction');
}

export default function CashbookEvidenceWorkspace({ transactions, approved_proposals, cash_execution_context }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [selection, setSelection] = useState<Selection | null>(
    approved_proposals[0]
      ? { type: 'proposal', id: approved_proposals[0].id }
      : transactions[0]
        ? { type: 'transaction', id: transactions[0].id }
        : null
  );

  const outflowCount = useMemo(() => transactions.filter((transaction) => transaction.direction === 'OUTFLOW').length, [transactions]);
  const selectedProposal = selection?.type === 'proposal'
    ? approved_proposals.find((proposal) => proposal.id === selection.id) ?? null
    : null;
  const selectedTransaction = selection?.type === 'transaction'
    ? transactions.find((transaction) => transaction.id === selection.id) ?? null
    : null;

  return (
    <div className="workspace">
      <WorkspaceHeader title="Cashbook Evidence Control">
        <Link href={route('finance.payables.payment-proposals.index')} preserveScroll className="btn btn-secondary">
          <Icon name="file-text" /> Payment Proposals
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <label className="filter-label">Current Property Scope</label>
              <div className="finance-context-note">
                Cashbook and approved proposal evidence projected for the active property.
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Evidence Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Cash Transactions: {transactions.length}</div>
                <div>Payment Outflows: {outflowCount}</div>
                <div>Approved Proposals: {approved_proposals.length}</div>
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Selected Evidence</label>
              <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
                {selectedProposal?.proposal_number || selectedTransaction?.journal_reference || selectedTransaction?.id || 'No evidence selected'}
              </div>
              <div className="finance-context-note">
                {selectedProposal ? 'Approved proposal' : selectedTransaction ? transactionLabel(selectedTransaction) : 'Select evidence to review.'}
              </div>
            </div>
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <label className="filter-label">Cash Execution Context</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Eligible Items: {cash_execution_context.eligible_items.length}</div>
                <div>Open Sessions: {cash_execution_context.cash_sessions.length}</div>
                <div>CASH Instruments: {cash_execution_context.cash_instruments.length}</div>
              </div>
              {cash_execution_context.eligible_items.length === 0 && (
                <div className="finance-context-note" style={{ marginTop: '4px' }}>
                  No payment proposal items are currently eligible for cash execution in this property.
                </div>
              )}
              {cash_execution_context.cash_sessions.length === 0 && (
                <div className="finance-context-note" style={{ marginTop: '2px' }}>
                  No open cashier session for the current actor.
                </div>
              )}
            </div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div className={`finance-flash ${flash.success ? 'success' : 'error'}`}>{flash.success || flash.error}</div>
          )}

          <OperationalSnapshot>
            <SnapshotCard value={approved_proposals.length} label="Approved Proposals" statusColor="ready-green" />
            <SnapshotCard value={transactions.length} label="Cash Transactions" statusColor="inspection-blue" />
            <SnapshotCard value={outflowCount} label="Payment Outflows" statusColor="critical-red" />
          </OperationalSnapshot>

          {approved_proposals.length === 0 && transactions.length === 0 && (
            <AttentionArea title="Cashbook Evidence" badgeText="No Data" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No approved payment proposals or cashbook transactions are projected for the current property.
              </div>
            </AttentionArea>
          )}

          {(approved_proposals.length > 0 || transactions.length > 0) && (
            <div className="finance-master-detail">
              <div style={{ display: 'grid', gap: '16px' }}>
                <QueueList title="Approved Payment Proposals" count={approved_proposals.length}>
                  <div className="finance-queue-body">
                    {approved_proposals.length === 0 && (
                      <div className="finance-empty-state">No approved payment proposals for the current property.</div>
                    )}
                    {approved_proposals.map((proposal) => (
                      <WorkCard
                        key={proposal.id}
                        className={selection?.type === 'proposal' && selection.id === proposal.id ? 'is-selected' : ''}
                        borderColor="ready-green"
                        meta={
                          <>
                            <span>{proposal.approved_at || 'Approval date unavailable'}</span>
                            <StatusBadge status="ready">Approved</StatusBadge>
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
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelection({ type: 'proposal', id: proposal.id })}>
                            <Icon name="search" /> Evidence
                          </Button>
                        }
                      />
                    ))}
                  </div>
                </QueueList>

                <QueueList title="Cashbook Transactions" count={transactions.length}>
                  <div className="finance-queue-body">
                    {transactions.length === 0 && (
                      <div className="finance-empty-state">No cashbook transactions recorded for the current property.</div>
                    )}
                    {transactions.map((transaction) => (
                      <WorkCard
                        key={transaction.id}
                        className={selection?.type === 'transaction' && selection.id === transaction.id ? 'is-selected' : ''}
                        borderColor={transaction.direction === 'OUTFLOW' ? 'critical-red' : 'inspection-blue'}
                        meta={
                          <>
                            <span>{transaction.posted_business_date || transaction.created_at || 'Date unavailable'}</span>
                            <StatusBadge status={transaction.direction === 'OUTFLOW' ? 'overdue' : 'inspection'}>{transaction.direction || 'Cash'}</StatusBadge>
                          </>
                        }
                        title={transactionLabel(transaction)}
                        detail={
                          <span>
                            {transaction.journal_reference || 'Journal reference unavailable'}
                            <br />
                            {transaction.amount} {transaction.currency_code}
                          </span>
                        }
                        actions={
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelection({ type: 'transaction', id: transaction.id })}>
                            <Icon name="search" /> Evidence
                          </Button>
                        }
                      />
                    ))}
                  </div>
                </QueueList>

                <QueueList title="Cash Execution Context" count={cash_execution_context.eligible_items.length}>
                  <div className="finance-queue-body">
                    {cash_execution_context.eligible_items.length === 0 && (
                      <div className="finance-empty-state">No eligible payment proposal items for cash execution in the current property.</div>
                    )}
                    {cash_execution_context.eligible_items.map((item) => (
                      <WorkCard
                        key={item.id}
                        borderColor="inspection-blue"
                        meta={
                          <>
                            <span>{item.invoice_number || 'No invoice'}</span>
                            <StatusBadge status="inspection">Eligible</StatusBadge>
                          </>
                        }
                        title={item.proposal_number || 'Unreferenced proposal'}
                        detail={
                          <span>
                            {item.amount} {item.currency_code}
                          </span>
                        }
                        actions={null}
                      />
                    ))}
                  </div>
                </QueueList>
              </div>

              {selectedProposal && <ProposalEvidence proposal={selectedProposal} />}
              {selectedTransaction && <TransactionEvidence transaction={selectedTransaction} />}
              {!selectedProposal && !selectedTransaction && (
                <AttentionArea title="Selected Cash Evidence" badgeText="None" badgeType="neutral" areaType="neutral">
                  <div className="finance-empty-state">Select a proposal or cashbook transaction to review evidence.</div>
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
    <AttentionArea title="Selected Payment Proposal" badgeText="Approved" badgeType="ready" areaType="inspection">
      <div className="finance-evidence-grid">
        <EvidenceCell label="Proposal" value={proposal.proposal_number} />
        <EvidenceCell label="Supplier" value={proposal.vendor_name || 'Unknown Vendor'} />
        <EvidenceCell label="Approved At" value={proposal.approved_at || 'N/A'} />
        <EvidenceCell label="Amount" value={`${proposal.total_amount} ${proposal.currency_code}`} />
      </div>
      <div className="finance-evidence-section">
        <div className="finance-section-title">Payment Proposal</div>
        <div className="finance-context-note">
          This proposal is eligible cash evidence only because the server projected it in the approved proposal collection.
        </div>
      </div>
    </AttentionArea>
  );
}

function TransactionEvidence({ transaction }: { transaction: Transaction }) {
  return (
    <AttentionArea
      title="Selected Cash Evidence"
      badgeText={transaction.direction || 'Cash'}
      badgeType={transaction.direction === 'OUTFLOW' ? 'overdue' : 'inspection'}
      areaType="inspection"
    >
      <div className="finance-evidence-grid">
        <EvidenceCell label="Direction" value={transaction.direction || 'N/A'} />
        <EvidenceCell label="Amount" value={`${transaction.amount} ${transaction.currency_code}`} />
        <EvidenceCell label="Business Date" value={transaction.posted_business_date || 'N/A'} />
        <EvidenceCell label="Journal Reference" value={transaction.journal_reference || 'N/A'} />
      </div>
      <div className="finance-evidence-section">
        <div className="finance-section-title">Lifecycle / History</div>
        <div className="finance-evidence-list">
          <EvidenceRow label="Created" value={transaction.created_at || 'N/A'} />
          <EvidenceRow label="Transaction ID" value={transaction.id} />
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

CashbookEvidenceWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
