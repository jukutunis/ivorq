import React, { FormEvent } from 'react';
import { router, useForm } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import Button from '../../../Components/Ivorq/primitives/Button';

type Capabilities = Record<string, boolean>;
type Version = { id: string; number: number; status: string; line_count: number; total: string; created_by?: string | null };
type PlanningRecord = { id: string; name: string; fiscal_year: number; versions: Version[] };
type Account = { id: string; code: string; name: string };

interface FinanceWorkspaceProps {
  activeTab?: string;
  capabilities?: Capabilities;
  workspace: any;
}

const value = (input: unknown) => typeof input === 'number'
  ? new Intl.NumberFormat(undefined, { maximumFractionDigits: 2 }).format(input)
  : String(input ?? '—');

const money = (input: unknown, currency?: string) => {
  const amount = Number(input ?? 0);
  if (!currency) return value(amount);
  try {
    return new Intl.NumberFormat(undefined, { style: 'currency', currency }).format(amount);
  } catch {
    return `${currency} ${value(amount)}`;
  }
};

const Empty = ({ children }: { children: React.ReactNode }) => (
  <div className="work-card" role="status" style={{ color: 'var(--text-secondary)' }}>{children}</div>
);

const DataTable = ({ columns, rows }: { columns: [string, string][]; rows: any[] }) => (
  <div style={{ overflowX: 'auto' }}>
    <table className="data-table">
      <thead><tr>{columns.map(([key, label]) => <th key={key}>{label}</th>)}</tr></thead>
      <tbody>{rows.map((row, index) => <tr key={row.id ?? index}>{columns.map(([key]) => <td key={key}>{value(row[key])}</td>)}</tr>)}</tbody>
    </table>
  </div>
);

const AccountsReceivablePanel = ({ data }: { data: any }) => {
  if (!data) return <Empty>Accounts Receivable authority is not available to this user.</Empty>;
  return <>
    <section className="work-card">
      <h2>City Ledger transfer exposure</h2>
      <p>{data.authority_note}</p>
      <OperationalSnapshot>
        {Object.entries(data.totals_by_currency ?? {}).map(([currency, total]: [string, any]) =>
          <SnapshotCard key={currency} value={money(total.amount, currency)} label={`${total.count} accepted transfer(s)`} statusColor="inspection-blue" />)}
        {Object.entries(data.aging ?? {}).map(([bucket, count]) =>
          <SnapshotCard key={bucket} value={value(count)} label={`${bucket.replaceAll('_', '–')} days`} statusColor="warning-amber" />)}
      </OperationalSnapshot>
    </section>
    {data.items.length === 0 ? <Empty>No accepted, unreversed Guest Ledger transfers exist for this Property.</Empty> :
      <section className="work-card"><h3>Accepted transfer evidence</h3><DataTable columns={[
        ['transfer_number', 'Transfer'], ['folio_id', 'Folio'], ['guest_id', 'Guest'], ['currency', 'Currency'],
        ['amount', 'Exposure'], ['accepted_at', 'Accepted at'], ['age_days', 'Age'], ['aging_bucket', 'Bucket'],
        ['source_decision_id', 'Decision evidence'],
      ]} rows={data.items} /></section>}
  </>;
};

const TreasuryPanel = ({ data }: { data: any }) => {
  if (!data) return <Empty>Treasury authority is not available to this user.</Empty>;
  const receivables = Object.entries(data.accepted_receivables_by_currency ?? {}).map(([currency, totals]: [string, any]) => ({ currency, ...totals }));
  const payables = Object.entries(data.approved_payables_by_currency ?? {}).map(([currency, totals]: [string, any]) => ({ currency, ...totals }));
  return <>
    <OperationalSnapshot>
      <SnapshotCard value={money(data.cash_position)} label="GL cash-equivalent position" statusColor="ready-green" />
      <SnapshotCard value={data.bank_snapshots.length} label="Current bank snapshot sources" statusColor="inspection-blue" />
      <SnapshotCard value={receivables.reduce((sum, row) => sum + row.count, 0)} label="Accepted AR transfers" statusColor="inspection-blue" />
      <SnapshotCard value={payables.reduce((sum, row) => sum + row.count, 0)} label="Approved AP invoices" statusColor="warning-amber" />
    </OperationalSnapshot>
    <section className="work-card"><h3>Latest immutable bank snapshots</h3>
      {data.bank_snapshots.length ? <DataTable columns={[["bank_account_id", "Bank account"], ["snapshot_date", "Snapshot date"], ["balance", "Balance"]]} rows={data.bank_snapshots} /> : <p>No bank balance snapshots are registered.</p>}
    </section>
    <section className="work-card"><h3>Source-owned inflow and outflow exposure</h3>
      <h4>Accepted AR transfers</h4>{receivables.length ? <DataTable columns={[["currency", "Currency"], ["count", "Items"], ["amount", "Amount"]]} rows={receivables} /> : <p>No accepted AR transfer exposure.</p>}
      <h4>Approved AP invoices</h4>{payables.length ? <DataTable columns={[["currency", "Currency"], ["count", "Items"], ["amount", "Amount"], ["overdue_count", "Past due"]]} rows={payables} /> : <p>No approved AP invoice exposure.</p>}
    </section>
    <section className="work-card"><h3>Treasury alerts</h3>{data.alerts.length ? <DataTable columns={[["severity", "Severity"], ["type", "Type"], ["message", "Message"], ["logged_at", "Logged at"]]} rows={data.alerts} /> : <p>No recorded alerts.</p>}</section>
  </>;
};

const LineForm = ({ kind, version, accounts }: { kind: 'budgeting' | 'forecasting'; version: Version; accounts: Account[] }) => {
  const form = useForm({ account_id: '', department_id: '', period_month: new Date().getMonth() + 1, amount: '' });
  const submit = (event: FormEvent) => {
    event.preventDefault();
    form.post(`/finance/${kind}/versions/${version.id}/lines`, { onSuccess: () => form.reset('account_id', 'department_id', 'amount') });
  };
  return <form onSubmit={submit} style={{ display: 'grid', gridTemplateColumns: 'minmax(180px, 2fr) 90px 120px auto', gap: 8, marginTop: 12 }}>
    <select className="filter-input" value={form.data.account_id} onChange={(event) => form.setData('account_id', event.target.value)} required>
      <option value="">Select P&amp;L account</option>{accounts.map(account => <option key={account.id} value={account.id}>{account.code} — {account.name}</option>)}
    </select>
    <input className="filter-input" type="number" min={1} max={12} value={form.data.period_month} onChange={(event) => form.setData('period_month', Number(event.target.value))} aria-label="Period month" />
    <input className="filter-input" type="number" min={0} step="0.01" value={form.data.amount} onChange={(event) => form.setData('amount', event.target.value)} placeholder="Amount" required />
    <Button type="submit" size="sm" disabled={form.processing}>Save line</Button>
  </form>;
};

const PlanningPanel = ({ kind, data, capabilities, accounts }: { kind: 'budget' | 'forecast'; data: any; capabilities: Capabilities; accounts: Account[] }) => {
  const routeKind = kind === 'budget' ? 'budgeting' : 'forecasting';
  const title = kind === 'budget' ? 'Budgeting' : 'Forecasting';
  const canCreate = capabilities[`can_create_${kind}`];
  const canEdit = capabilities[`can_edit_${kind}`];
  const canSubmit = capabilities[`can_submit_${kind}`];
  const canApprove = capabilities[`can_approve_${kind}`];
  const create = useForm({ fiscal_year: new Date().getFullYear(), name: '' });
  const submitCreate = (event: FormEvent) => { event.preventDefault(); create.post(`/finance/${routeKind}`, { onSuccess: () => create.reset('name') }); };
  const transition = (version: Version, action: 'submit' | 'approve' | 'reject') => router.post(`/finance/${routeKind}/versions/${version.id}/transition`, { action });
  if (!data) return <Empty>{title} authority is not available to this user.</Empty>;
  const records: PlanningRecord[] = data.records ?? [];
  return <>
    {canCreate && <form className="work-card" onSubmit={submitCreate} style={{ display: 'flex', gap: 8, alignItems: 'end', flexWrap: 'wrap' }}>
      <div><label className="filter-label">Fiscal year</label><input className="filter-input" type="number" min={2000} max={2200} value={create.data.fiscal_year} onChange={(event) => create.setData('fiscal_year', Number(event.target.value))} /></div>
      <div style={{ minWidth: 240 }}><label className="filter-label">{title} name</label><input className="filter-input" value={create.data.name} onChange={(event) => create.setData('name', event.target.value)} required /></div>
      <Button type="submit" disabled={create.processing}>Create {kind}</Button>
    </form>}
    {records.length === 0 ? <Empty>No {kind} exists for the selected Property and year.</Empty> : records.map(record => <section className="work-card" key={record.id}>
      <h3>{record.name} · FY {record.fiscal_year}</h3>
      {record.versions.map(version => <div key={version.id} style={{ borderTop: '1px solid var(--border-subtle)', padding: '12px 0' }}>
        <div style={{ display: 'flex', gap: 10, alignItems: 'center', flexWrap: 'wrap' }}>
          <strong>Version {version.number}</strong><span>{version.status}</span><span>{version.line_count} lines</span><span>{money(version.total)}</span>
          {version.status === 'Draft' && canSubmit && <Button size="sm" onClick={() => transition(version, 'submit')}>Submit</Button>}
          {version.status === 'Submitted' && canApprove && <><Button size="sm" onClick={() => transition(version, 'approve')}>Approve &amp; lock</Button><Button variant="secondary" size="sm" onClick={() => transition(version, 'reject')}>Reject</Button></>}
        </div>
        {version.status === 'Draft' && canEdit && <LineForm kind={routeKind} version={version} accounts={accounts} />}
      </div>)}
    </section>)}
    <section className="work-card"><h3>{title} variance</h3>{data.variance?.length ? <DataTable columns={Object.keys(data.variance[0]).map(key => [key, key.replaceAll('_', ' ')]) as [string, string][]} rows={data.variance} /> : <p>No locked planning version is available for variance projection.</p>}</section>
  </>;
};

const FinanceWorkspace = ({ activeTab = 'revenue_cash', capabilities = {}, workspace }: FinanceWorkspaceProps) => {
  const tabs = [
    { href: '/finance/revenue-cash', label: 'Finance Overview' },
    ...(capabilities.can_view_payables ? [{ href: '/finance/accounts-payable', label: 'Accounts Payable' }] : []),
    ...(capabilities.can_view_ar ? [{ href: '/finance/accounts-receivable', label: 'Accounts Receivable' }] : []),
    ...(capabilities.can_view_treasury ? [{ href: '/finance/treasury', label: 'Treasury' }] : []),
    ...(capabilities.can_view_budget ? [{ href: '/finance/budget-watch', label: 'Budgeting' }] : []),
    ...(capabilities.can_view_forecast ? [{ href: '/finance/forecasting', label: 'Forecasting' }] : []),
    ...(capabilities.can_view_general_ledger ? [{ href: '/finance/general-ledger/operations', label: 'General Ledger' }] : []),
    ...(capabilities.can_view_financial_reports ? [{ href: '/finance/general-ledger/reports', label: 'Financial Reports' }] : []),
    ...(capabilities.can_manage_financial_period ? [{ href: '/finance/general-ledger/period-control', label: 'Period Control' }] : []),
    ...(capabilities.can_view_fx_rates ? [{ href: '/finance/fx-rates', label: 'FX Rates' }] : []),
    ...(capabilities.can_view_fx_adjustments ? [{ href: '/finance/fx-adjustments', label: 'Realized FX' }] : []),
  ];
  const applyPeriod = (event: FormEvent<HTMLFormElement>) => {
    event.preventDefault();
    const form = new FormData(event.currentTarget);
    router.get(window.location.pathname, { year: form.get('year'), month: form.get('month') }, { preserveState: true });
  };
  return <div className="workspace">
    <WorkspaceHeader title="Operational Finance" />
    <ModuleTabs tabs={tabs} />
    <SplitLayout>
      <QuickFilterPanel>
        <form onSubmit={applyPeriod}>
          <div className="filter-group"><label className="filter-label">Fiscal year</label><input name="year" className="filter-input" type="number" min={2000} max={2200} defaultValue={workspace.scope.year} /></div>
          <div className="filter-group"><label className="filter-label">Period month</label><input name="month" className="filter-input" type="number" min={1} max={12} defaultValue={workspace.scope.month} /></div>
          <Button type="submit" variant="secondary">Apply period</Button>
        </form>
        <div style={{ marginTop: 20, fontSize: 12 }}><strong>Source authority</strong>{Object.entries(workspace.authority).map(([key, item]) => <p key={key}>{key.replaceAll('_', ' ')}: {String(item)}</p>)}</div>
      </QuickFilterPanel>
      <MainContent>
        {activeTab === 'revenue_cash' && <>
          <OperationalSnapshot>{workspace.summary.map((card: any) => <SnapshotCard key={card.key} value={value(card.value)} label={card.label} statusColor="inspection-blue" />)}</OperationalSnapshot>
          <section className="work-card"><h2>Current-scope Finance control plane</h2><p>Every value on this page is projected server-side for the active Property. Reading this workspace performs no financial mutation.</p></section>
          <section className="work-card"><h3>Explicitly deferred boundaries</h3><ul>{workspace.deferred.map((item: string) => <li key={item}>{item}</li>)}</ul></section>
        </>}
        {activeTab === 'accounts_payable' && <section className="work-card"><h2>Accounts Payable</h2><p>Use the registered Supplier Invoice, payment proposal, General Cashier, and Banking workspaces for controlled AP operations.</p></section>}
        {activeTab === 'accounts_receivable' && <AccountsReceivablePanel data={workspace.accounts_receivable} />}
        {activeTab === 'treasury' && <TreasuryPanel data={workspace.treasury} />}
        {activeTab === 'budget_watch' && <PlanningPanel kind="budget" data={workspace.budget} capabilities={capabilities} accounts={workspace.planning_accounts} />}
        {activeTab === 'forecasting' && <PlanningPanel kind="forecast" data={workspace.forecast} capabilities={capabilities} accounts={workspace.planning_accounts} />}
      </MainContent>
    </SplitLayout>
  </div>;
};

FinanceWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FinanceWorkspace;
