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

interface BankAccount {
  id: string;
  bank_name: string;
  account_name: string;
  external_account_reference: string | null;
  currency_code: string | null;
}

interface BankStatementLine {
  id: string;
  controlled_bank_account_id: string;
  amount: string;
  currency_code: string | null;
  statement_date: string | null;
  external_reference: string | null;
  vendor_reference: string | null;
}

interface BankExecutionEvidence {
  id: string;
  payment_proposal_id: string;
  payment_proposal_item_id: string;
  source_amount: string;
  currency_code: string;
  executed_at: string | null;
  controlled_bank_account_id: string;
  controlled_bank_statement_line_id: string;
  source_journal_entry_id: string;
}

interface ReconciliationEvidence {
  id: string;
  controlled_bank_account_id: string;
  controlled_bank_statement_line_id: string;
  payment_execution_id: string;
  posted_journal_entry_id: string;
  payment_amount: string;
  statement_amount: string;
  difference_amount: string;
  currency_code: string;
  status: string;
  reconciled_at: string | null;
}

interface Props {
  bank_accounts: BankAccount[];
  statement_lines: BankStatementLine[];
  bank_execution_evidence: BankExecutionEvidence[];
  reconciliation_evidence: ReconciliationEvidence[];
  permissions: {
    can_execute_bank: boolean;
    can_reconcile_bank: boolean;
  };
}

type Selection =
  | { type: 'account'; id: string }
  | { type: 'execution'; id: string }
  | { type: 'reconciliation'; id: string };

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/banking/operations', label: 'Banking Operations' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

export default function BankingOperationsWorkspace({ bank_accounts, statement_lines, bank_execution_evidence, reconciliation_evidence, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [selection, setSelection] = useState<Selection | null>(
    bank_accounts[0] ? { type: 'account', id: bank_accounts[0].id } : null
  );

  const statementCount = useMemo(() => statement_lines.length, [statement_lines]);
  const executionCount = useMemo(() => bank_execution_evidence.length, [bank_execution_evidence]);
  const reconciliationCount = useMemo(() => reconciliation_evidence.length, [reconciliation_evidence]);

  const selectedAccount = selection?.type === 'account'
    ? bank_accounts.find((a) => a.id === selection.id) ?? null
    : null;
  const selectedExecution = selection?.type === 'execution'
    ? bank_execution_evidence.find((e) => e.id === selection.id) ?? null
    : null;
  const selectedReconciliation = selection?.type === 'reconciliation'
    ? reconciliation_evidence.find((r) => r.id === selection.id) ?? null
    : null;

  const allStatementLines = statement_lines;
  const reconciledStatementLineIds = new Set(reconciliation_evidence.map((r) => r.controlled_bank_statement_line_id));
  const executedStatementLineIds = new Set(bank_execution_evidence.map((e) => e.controlled_bank_statement_line_id));

  return (
    <div className="workspace">
      <WorkspaceHeader title="Banking Operations">
        <Link href={route('finance.payables.cashbook-evidence.index')} preserveScroll className="btn btn-secondary">
          <Icon name="file-text" /> Cashbook Evidence
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <label className="filter-label">Current Property Scope</label>
              <div className="finance-context-note">
                Banking evidence projected for the active property.
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Evidence Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Bank Accounts: {bank_accounts.length}</div>
                <div>Statement Lines (OUTFLOW): {statementCount}</div>
                <div>Bank Executions: {executionCount}</div>
                <div>Reconciliations: {reconciliationCount}</div>
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Selected Evidence</label>
              <div style={{ fontSize: '13px', fontWeight: 700, wordBreak: 'break-word' }}>
                {selectedAccount?.account_name || selectedExecution?.id || selectedReconciliation?.id || 'No evidence selected'}
              </div>
              <div className="finance-context-note">
                {selectedAccount ? 'Controlled Bank Account' : selectedExecution ? 'Bank Payment Execution' : selectedReconciliation ? 'Reconciliation Record' : 'Select evidence to review.'}
              </div>
            </div>
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <label className="filter-label">Bank Execution Capability</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Execute: {permissions.can_execute_bank ? 'Authorized' : 'Unauthorized'}</div>
                <div>Reconcile: {permissions.can_reconcile_bank ? 'Authorized' : 'Unauthorized'}</div>
              </div>
              {!permissions.can_execute_bank && (
                <div className="finance-context-note" style={{ marginTop: '4px' }}>
                  Bank payment execution permission is not assigned to the current actor.
                </div>
              )}
              {!permissions.can_reconcile_bank && (
                <div className="finance-context-note" style={{ marginTop: '2px' }}>
                  Bank reconciliation permission is not assigned to the current actor.
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
            <SnapshotCard value={bank_accounts.length} label="Bank Accounts" statusColor="ready-green" />
            <SnapshotCard value={statementCount} label="Statement Lines" statusColor="inspection-blue" />
            <SnapshotCard value={executionCount} label="Bank Executions" statusColor="critical-red" />
            <SnapshotCard value={reconciliationCount} label="Reconciliations" statusColor="ready-green" />
          </OperationalSnapshot>

          {bank_accounts.length === 0 && bank_execution_evidence.length === 0 && reconciliation_evidence.length === 0 && (
            <AttentionArea title="Banking Operations" badgeText="No Data" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No controlled bank accounts, bank payment executions, or reconciliation records are projected for the current property.
              </div>
            </AttentionArea>
          )}

          {(bank_accounts.length > 0 || bank_execution_evidence.length > 0 || reconciliation_evidence.length > 0) && (
            <div className="finance-master-detail">
              <div style={{ display: 'grid', gap: '16px' }}>
                <QueueList title="Controlled Bank Accounts" count={bank_accounts.length}>
                  <div className="finance-queue-body">
                    {bank_accounts.length === 0 && (
                      <div className="finance-empty-state">No active controlled bank accounts for the current property.</div>
                    )}
                    {bank_accounts.map((account) => (
                      <WorkCard
                        key={account.id}
                        className={selection?.type === 'account' && selection.id === account.id ? 'is-selected' : ''}
                        borderColor="ready-green"
                        meta={<StatusBadge status="ready">Active</StatusBadge>}
                        title={account.account_name}
                        detail={
                          <span>
                            {account.bank_name}
                            {account.external_account_reference && (
                              <>
                                <br />
                                Ref: {account.external_account_reference}
                              </>
                            )}
                            <br />
                            {account.currency_code}
                          </span>
                        }
                        actions={
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelection({ type: 'account', id: account.id })}>
                            <Icon name="search" /> Evidence
                          </Button>
                        }
                      />
                    ))}
                  </div>
                </QueueList>

                <QueueList title="Bank Statement Lines (OUTFLOW)" count={statementCount}>
                  <div className="finance-queue-body">
                    {allStatementLines.length === 0 && (
                      <div className="finance-empty-state">No OUTFLOW statement lines for the current property.</div>
                    )}
                    {allStatementLines.map((line) => {
                      const isReconciled = reconciledStatementLineIds.has(line.id);
                      const isExecuted = executedStatementLineIds.has(line.id);
                      return (
                        <WorkCard
                          key={line.id}
                          borderColor={isReconciled ? 'ready-green' : isExecuted ? 'inspection-blue' : 'neutral'}
                          meta={
                            <>
                              <span>{line.statement_date || 'Date unavailable'}</span>
                              <StatusBadge status={isReconciled ? 'ready' : isExecuted ? 'inspection' : 'neutral'}>
                                {isReconciled ? 'Reconciled' : isExecuted ? 'Executed' : 'Pending'}
                              </StatusBadge>
                            </>
                          }
                          title={line.external_reference || line.id}
                          detail={
                            <span>
                              Amount: {line.amount} {line.currency_code}
                              {line.vendor_reference && (
                                <>
                                  <br />
                                  Vendor: {line.vendor_reference}
                                </>
                              )}
                            </span>
                          }
                          actions={<></>}
                        />
                      );
                    })}
                  </div>
                </QueueList>

                <QueueList title="Bank Payment Executions" count={executionCount}>
                  <div className="finance-queue-body">
                    {bank_execution_evidence.length === 0 && (
                      <div className="finance-empty-state">No bank payment executions recorded for the current property.</div>
                    )}
                    {bank_execution_evidence.map((execution) => (
                      <WorkCard
                        key={execution.id}
                        className={selection?.type === 'execution' && selection.id === execution.id ? 'is-selected' : ''}
                        borderColor="critical-red"
                        meta={<span>{execution.executed_at || 'Execution date unavailable'}</span>}
                        title={`Execution ${execution.id}`}
                        detail={
                          <span>
                            Amount: {execution.source_amount} {execution.currency_code}
                            <br />
                            Bank Account: {execution.controlled_bank_account_id}
                            <br />
                            Statement Line: {execution.controlled_bank_statement_line_id}
                          </span>
                        }
                        actions={
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelection({ type: 'execution', id: execution.id })}>
                            <Icon name="search" /> Evidence
                          </Button>
                        }
                      />
                    ))}
                  </div>
                </QueueList>

                <QueueList title="Bank Payment Reconciliations" count={reconciliationCount}>
                  <div className="finance-queue-body">
                    {reconciliation_evidence.length === 0 && (
                      <div className="finance-empty-state">No bank payment reconciliation records for the current property.</div>
                    )}
                    {reconciliation_evidence.map((rec) => (
                      <WorkCard
                        key={rec.id}
                        className={selection?.type === 'reconciliation' && selection.id === rec.id ? 'is-selected' : ''}
                        borderColor="ready-green"
                        meta={
                          <>
                            <span>{rec.reconciled_at || 'Date unavailable'}</span>
                            <StatusBadge status="ready">{rec.status}</StatusBadge>
                          </>
                        }
                        title={`Reconciliation ${rec.id}`}
                        detail={
                          <span>
                            Payment: {rec.payment_amount} {rec.currency_code}
                            <br />
                            Statement: {rec.statement_amount} {rec.currency_code}
                            <br />
                            Difference: {rec.difference_amount}
                          </span>
                        }
                        actions={
                          <Button type="button" size="sm" variant="secondary" onClick={() => setSelection({ type: 'reconciliation', id: rec.id })}>
                            <Icon name="search" /> Evidence
                          </Button>
                        }
                      />
                    ))}
                  </div>
                </QueueList>
              </div>
            </div>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

BankingOperationsWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
