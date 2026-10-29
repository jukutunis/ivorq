import React, { useMemo, useState } from 'react';
import { router } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';
import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';

const tabs = [
  { href: '/finance/general-ledger/operations', label: 'Ledger Operations' }, { href: '/finance/general-ledger/reports', label: 'Financial Reports' }, { href: '/finance/general-ledger/period-control', label: 'Period Control' }, { href: '/finance/fx-rates', label: 'FX Rate Evidence' }, { href: '/finance/banking/operations', label: 'Banking Evidence' },
];
const reportLabels: Record<string, string> = { trial_balance: 'Trial Balance', profit_loss: 'Profit & Loss', balance_sheet: 'Balance Sheet', cash_flow: 'Cash Flow' };
const fmt = (value: unknown) => typeof value === 'number' ? new Intl.NumberFormat(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 }).format(value) : String(value ?? '—');

const FinancialReportingWorkspace = ({ period, reports, permissions, authority }: { period: { year: number; month: number; status: string | null }; reports: Record<string, any>; permissions: Record<string, boolean>; authority: string }) => {
  const available = Object.keys(reports).filter((key) => permissions[key]);
  const [active, setActive] = useState(available[0] ?? '');
  const [year, setYear] = useState(String(period.year)); const [month, setMonth] = useState(String(period.month));
  const report = useMemo(() => reports[active], [reports, active]);
  const sections = report ? Object.entries(report).filter(([, value]) => Array.isArray(value)) : [];
  const totals = report ? Object.entries(report).filter(([, value]) => !Array.isArray(value)) : [];
  const submit = (event: React.FormEvent) => { event.preventDefault(); router.get('/finance/general-ledger/reports', { year, month }, { preserveState: true, replace: true }); };
  return <div className="workspace"><WorkspaceHeader title="Financial Reporting" /><ModuleTabs tabs={tabs} /><SplitLayout><QuickFilterPanel><form onSubmit={submit}><div className="filter-group"><label className="filter-label">Year</label><input className="filter-input" inputMode="numeric" value={year} onChange={(e) => setYear(e.target.value)} /></div><div className="filter-group"><label className="filter-label">Month</label><select className="filter-input" value={month} onChange={(e) => setMonth(e.target.value)}>{Array.from({ length: 12 }, (_, i) => <option key={i + 1} value={i + 1}>{i + 1}</option>)}</select></div><button className="btn btn-primary" type="submit">Generate</button></form><div style={{ marginTop: 20, fontSize: 12 }}><strong>Authority</strong><p>{authority}</p><p>Period state: {period.status ?? 'Not initialized'}</p></div></QuickFilterPanel><MainContent><div className="work-card"><div role="tablist" style={{ display: 'flex', gap: 8, flexWrap: 'wrap' }}>{available.map((key) => <button key={key} className={`btn ${active === key ? 'btn-primary' : 'btn-secondary'}`} onClick={() => setActive(key)}>{reportLabels[key]}</button>)}</div><h2 style={{ marginTop: 20 }}>{reportLabels[active]}</h2><div className="operational-snapshot">{totals.map(([key, value]) => <div className="snapshot-card" key={key}><div className="snapshot-value">{fmt(value)}</div><div className="snapshot-label">{key.replaceAll('_', ' ')}</div></div>)}</div>{sections.map(([name, rows]) => <section key={name}><h3>{name.replaceAll('_', ' ')}</h3><div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr>{Object.keys((rows as any[])[0] ?? {}).map((key) => <th key={key}>{key.replaceAll('_', ' ')}</th>)}</tr></thead><tbody>{(rows as any[]).map((row, index) => <tr key={row.account_id ?? row.account_code ?? index}>{Object.values(row).map((value, cell) => <td key={cell}>{fmt(value)}</td>)}</tr>)}</tbody></table></div></section>)}</div></MainContent></SplitLayout></div>;
};

FinancialReportingWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FinancialReportingWorkspace;
