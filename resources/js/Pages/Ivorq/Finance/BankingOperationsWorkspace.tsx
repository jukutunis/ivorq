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

interface EligibleItem {
  id: string;
  proposal_number: string | null;
  invoice_number: string | null;
  amount: string;
  currency_code: string;
  vendor_id: string | null;
}

interface BankSession {
  id: string;
  status: string;
  opened_at: string | null;
}

interface BankInstrument {
  id: string;
  name: string;
  type: string;
}

interface ExecBankAccount {
  id: string;
  account_name: string;
  bank_name: string;
  currency_code: string | null;
}

interface ExecStatementLine {
  id: string;
  controlled_bank_account_id: string;
  amount: string;
  currency_code: string | null;
  statement_date: string | null;
  external_reference: string | null;
}

interface BankExecutionContext {
  eligible_items: EligibleItem[];
  bank_sessions: BankSession[];
  bank_instruments: BankInstrument[];
  bank_accounts: ExecBankAccount[];
  statement_lines: ExecStatementLine[];
}

interface ReconciliationSessionRecord {
  id: string;
  status: string;
  bank_account_id: string;
  bank_account_name: string | null;
  bank_name: string | null;
  currency_code: string | null;
  statement_date_start: string | null;
  statement_date_end: string | null;
  opening_balance: string;
  reconciled_balance: string;
  unreconciled_balance: string;
  matches_count: number;
  completed_at: string | null;
  finalized_at: string | null;
  created_at: string | null;
}

interface Props {
  bank_accounts: BankAccount[];
  statement_lines: BankStatementLine[];
  bank_execution_evidence: BankExecutionEvidence[];
  reconciliation_evidence: ReconciliationEvidence[];
  reconciliation_sessions: ReconciliationSessionRecord[];
  bank_execution_context: BankExecutionContext;
  permissions: {
    can_execute_bank: boolean;
    can_reconcile_bank: boolean;
    can_view_reconciliation_sessions: boolean;
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

export default function BankingOperationsWorkspace({ bank_accounts, statement_lines, bank_execution_evidence, reconciliation_evidence, reconciliation_sessions, bank_execution_context, permissions }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [selection, setSelection] = useState<Selection | null>(
    bank_accounts[0] ? { type: 'account', id: bank_accounts[0].id } : null
  );
  const [showExecuteForm, setShowExecuteForm] = useState<boolean>(false);
  const [showReconcileForm, setShowReconcileForm] = useState<boolean>(false);

  const statementCount = useMemo(() => statement_lines.length, [statement_lines]);
  const executionCount = useMemo(() => bank_execution_evidence.length, [bank_execution_evidence]);
  const reconciliationCount = useMemo(() => reconciliation_evidence.length, [reconciliation_evidence]);
  const sessionCount = useMemo(() => reconciliation_sessions.length, [reconciliation_sessions]);

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
  const unreconciledStatementLines = allStatementLines.filter((line) => !reconciledStatementLineIds.has(line.id));

  const context = bank_execution_context || {};
  const canExecuteBank = permissions?.can_execute_bank ?? false;
  const canReconcileBank = permissions?.can_reconcile_bank ?? false;
  const hasExecutionContext = (context.eligible_items?.length ?? 0) > 0
    && (context.bank_sessions?.length ?? 0) > 0
    && (context.bank_instruments?.length ?? 0) > 0
    && (context.bank_accounts?.length ?? 0) > 0
    && (context.statement_lines?.length ?? 0) > 0;

  const executeForm = useForm<{
    payment_proposal_item_id: string;
    cashier_session_id: string;
    bank_payment_instrument_id: string;
    controlled_bank_account_id: string;
    controlled_bank_statement_line_id: string;
  }>({
    payment_proposal_item_id: '',
    cashier_session_id: '',
    bank_payment_instrument_id: '',
    controlled_bank_account_id: '',
    controlled_bank_statement_line_id: '',
  });

  const reconcileForm = useForm<{
    posted_journal_entry_id: string;
    controlled_bank_statement_line_id: string;
  }>({
    posted_journal_entry_id: '',
    controlled_bank_statement_line_id: '',
  });

  const submitExecute = (event: React.FormEvent) => {
    event.preventDefault();
    executeForm.post(route('finance.banking.bank-payment-execute.execute'), {
      preserveScroll: true,
      onSuccess: () => {
        setShowExecuteForm(false);
        executeForm.reset();
      },
    });
  };

  const submitReconcile = (event: React.FormEvent) => {
    event.preventDefault();
    reconcileForm.post(route('finance.banking.bank-reconciliation.reconcile'), {
      preserveScroll: true,
      onSuccess: () => {
        setShowReconcileForm(false);
        reconcileForm.reset();
      },
    });
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title="Banking Operations">
        <div style={{ display: 'flex', gap: '8px' }}>
          <Link href={route('finance.payables.cashbook-evidence.index')} preserveScroll className="btn btn-secondary">
            <Icon name="file-text" /> Cashbook Evidence
          </Link>
          {canExecuteBank && hasExecutionContext && (
            <Button type="button" variant="primary" onClick={() => setShowExecuteForm(!showExecuteForm)}>
              <Icon name="credit-card" /> {showExecuteForm ? 'Cancel' : 'Execute Bank Payment'}
            </Button>
          )}
          {canReconcileBank && (
            <Button type="button" variant="secondary" onClick={() => setShowReconcileForm(!showReconcileForm)}>
              <Icon name="check" /> {showReconcileForm ? 'Cancel' : 'Reconcile Bank Payment'}
            </Button>
          )}
        </div>
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
                <div>Recon Sessions: {sessionCount}</div>
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
                <div>View Sessions: {permissions.can_view_reconciliation_sessions ? 'Authorized' : 'Unauthorized'}</div>
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
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <label className="filter-label">Execution Context</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Eligible Items: {context.eligible_items?.length ?? 0}</div>
                <div>Open Sessions: {context.bank_sessions?.length ?? 0}</div>
                <div>BANK Instruments: {context.bank_instruments?.length ?? 0}</div>
                <div>Bank Accounts: {context.bank_accounts?.length ?? 0}</div>
                <div>Statement Lines: {context.statement_lines?.length ?? 0}</div>
              </div>
              {(context.eligible_items?.length ?? 0) === 0 && (
                <div className="finance-context-note" style={{ marginTop: '4px' }}>
                  No payment proposal items are currently eligible for bank execution in this property.
                </div>
              )}
              {(context.bank_sessions?.length ?? 0) === 0 && (
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

          {showExecuteForm && canExecuteBank && (
            <AttentionArea title="Bank Payment Execution" badgeText="Sensitive" badgeType="critical" areaType="critical">
              <form onSubmit={submitExecute} style={{ display: 'grid', gap: '12px' }}>
                <div className="filter-group">
                  <label className="filter-label">Payment Proposal Item</label>
                  <select
                    value={executeForm.data.payment_proposal_item_id}
                    onChange={(e) => executeForm.setData('payment_proposal_item_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select item...</option>
                    {(context.eligible_items ?? []).map((item) => (
                      <option key={item.id} value={item.id}>
                        {item.proposal_number || item.id} — {item.amount} {item.currency_code}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Cashier Session</label>
                  <select
                    value={executeForm.data.cashier_session_id}
                    onChange={(e) => executeForm.setData('cashier_session_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select session...</option>
                    {(context.bank_sessions ?? []).map((s) => (
                      <option key={s.id} value={s.id}>
                        {s.id} — {s.status}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Bank Payment Instrument</label>
                  <select
                    value={executeForm.data.bank_payment_instrument_id}
                    onChange={(e) => executeForm.setData('bank_payment_instrument_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select instrument...</option>
                    {(context.bank_instruments ?? []).map((inst) => (
                      <option key={inst.id} value={inst.id}>
                        {inst.name} ({inst.type})
                      </option>
                    ))}
                  </select>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Controlled Bank Account</label>
                  <select
                    value={executeForm.data.controlled_bank_account_id}
                    onChange={(e) => executeForm.setData('controlled_bank_account_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select account...</option>
                    {(context.bank_accounts ?? []).map((acc) => (
                      <option key={acc.id} value={acc.id}>
                        {acc.account_name} ({acc.bank_name}) — {acc.currency_code}
                      </option>
                    ))}
                  </select>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Controlled Bank Statement Line (OUTFLOW)</label>
                  <select
                    value={executeForm.data.controlled_bank_statement_line_id}
                    onChange={(e) => executeForm.setData('controlled_bank_statement_line_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select statement line...</option>
                    {(context.statement_lines ?? []).map((line) => (
                      <option key={line.id} value={line.id}>
                        {line.external_reference || line.id} — {line.amount} {line.currency_code} ({line.statement_date})
                      </option>
                    ))}
                  </select>
                </div>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <Button type="submit" variant="primary" disabled={executeForm.processing}>
                    <Icon name="check" /> Execute Bank Payment
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setShowExecuteForm(false)}>
                    Cancel
                  </Button>
                </div>
              </form>
            </AttentionArea>
          )}

          {showReconcileForm && canReconcileBank && (
            <AttentionArea title="Bank Payment Reconciliation" badgeText="Evidence" badgeType="inspection" areaType="inspection">
              <form onSubmit={submitReconcile} style={{ display: 'grid', gap: '12px' }}>
                <div className="filter-group">
                  <label className="filter-label">Posted Journal Entry ID</label>
                  <input
                    type="text"
                    value={reconcileForm.data.posted_journal_entry_id}
                    onChange={(e) => reconcileForm.setData('posted_journal_entry_id', e.target.value)}
                    placeholder="ULID of posted supplier payment disbursement journal"
                    maxLength={26}
                    style={{ width: '100%', padding: '6px 8px' }}
                  />
                  <div className="finance-context-note">
                    Must be a posted SupplierPaymentCashDisbursement journal entry.
                  </div>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Controlled Bank Statement Line</label>
                  <select
                    value={reconcileForm.data.controlled_bank_statement_line_id}
                    onChange={(e) => reconcileForm.setData('controlled_bank_statement_line_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select statement line...</option>
                    {unreconciledStatementLines.map((line) => (
                      <option key={line.id} value={line.id}>
                        {line.external_reference || line.id} — {line.amount} {line.currency_code} ({line.statement_date})
                      </option>
                    ))}
                  </select>
                </div>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <Button type="submit" variant="primary" disabled={reconcileForm.processing}>
                    <Icon name="check" /> Record Reconciliation
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setShowReconcileForm(false)}>
                    Cancel
                  </Button>
                </div>
              </form>
            </AttentionArea>
          )}

          <OperationalSnapshot>
            <SnapshotCard value={bank_accounts.length} label="Bank Accounts" statusColor="ready-green" />
            <SnapshotCard value={statementCount} label="Statement Lines" statusColor="inspection-blue" />
            <SnapshotCard value={executionCount} label="Bank Executions" statusColor="critical-red" />
            <SnapshotCard value={reconciliationCount} label="Reconciliations" statusColor="ready-green" />
            <SnapshotCard value={sessionCount} label="Recon Sessions" statusColor="inspection-blue" />
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

                <QueueList title="Reconciliation Sessions" count={sessionCount}>
                  <div className="finance-queue-body">
                    {reconciliation_sessions.length === 0 && (
                      <div className="finance-empty-state">No reconciliation sessions for the current property.</div>
                    )}
                    {reconciliation_sessions.map((session) => (
                      <WorkCard
                        key={session.id}
                        borderColor={session.status === 'Finalized' ? 'ready-green' : session.status === 'Completed' ? 'inspection-blue' : session.status === 'Cancelled' ? 'neutral' : 'critical-red'}
                        meta={
                          <>
                            <span>{session.statement_date_start || ''} — {session.statement_date_end || ''}</span>
                            <StatusBadge status={session.status === 'Finalized' || session.status === 'Completed' ? 'ready' : session.status === 'Cancelled' ? 'neutral' : 'inspection'}>
                              {session.status}
                            </StatusBadge>
                          </>
                        }
                        title={session.bank_account_name || session.bank_name || session.id}
                        detail={
                          <span>
                            {session.bank_name && session.bank_account_name ? `${session.bank_name} — ${session.bank_account_name}` : `Account: ${session.bank_account_id}`}
                            <br />
                            Currency: {session.currency_code || 'N/A'}
                            <br />
                            Matches: {session.matches_count}
                            <br />
                            Opening: {session.opening_balance} | Reconciled: {session.reconciled_balance} | Unreconciled: {session.unreconciled_balance}
                          </span>
                        }
                        actions={<></>}
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
