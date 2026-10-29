import React from 'react';
import { router } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';
import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';

const tabs = [
  { href: '/finance/general-ledger/operations', label: 'Ledger Operations' }, { href: '/finance/general-ledger/reports', label: 'Financial Reports' }, { href: '/finance/general-ledger/period-control', label: 'Period Control' }, { href: '/finance/fx-rates', label: 'FX Rate Evidence' }, { href: '/finance/banking/operations', label: 'Banking Evidence' },
];
type Period = { id: string; year: number; month: number; status: string; opened_at: string | null; opened_by: string | null; closed_at: string | null; closed_by: string | null };

const FinancialPeriodControlWorkspace = ({ periods, business_date, business_date_error, posting_readiness, authority }: { periods: Period[]; business_date: { date: string; status: string } | null; business_date_error: string | null; posting_readiness: { allowed: boolean; reason: string | null; period_id: string | null }; authority: Record<string, string> }) => <div className="workspace">
  <WorkspaceHeader title="Financial Period Control"><button className="btn btn-primary" disabled={periods.length > 0 || !business_date} onClick={() => router.post('/finance/general-ledger/period-control/initialize')}>Initialize first period</button></WorkspaceHeader>
  <ModuleTabs tabs={tabs} />
  <MainContent>
    <div className="operational-snapshot"><div className="snapshot-card"><div className="snapshot-value">{business_date?.date ?? 'Unavailable'}</div><div className="snapshot-label">Active Business Date</div></div><div className="snapshot-card"><div className="snapshot-value">{posting_readiness.allowed ? 'Posting ready' : 'Blocked'}</div><div className="snapshot-label">PostingPeriodGuard</div></div><div className="snapshot-card"><div className="snapshot-value">{periods.length}</div><div className="snapshot-label">Period history</div></div></div>
    {(business_date_error || posting_readiness.reason) && <div className="attention-area"><strong>Fail-closed status</strong><p>{business_date_error ?? posting_readiness.reason}</p></div>}
    <div className="work-card"><h3>Period history</h3><table className="data-table"><thead><tr><th>Period</th><th>Status</th><th>Opened</th><th>Closed</th><th>Authority</th></tr></thead><tbody>{periods.map((period) => <tr key={period.id}><td>{period.year}-{String(period.month).padStart(2, '0')}</td><td>{period.status}</td><td>{period.opened_at ?? '—'}<br /><small>{period.opened_by ?? ''}</small></td><td>{period.closed_at ?? '—'}<br /><small>{period.closed_by ?? ''}</small></td><td>Canonical persisted period</td></tr>)}</tbody></table></div>
    <div className="work-card"><h3>Control boundary</h3><dl>{Object.entries(authority).map(([key, value]) => <React.Fragment key={key}><dt>{key.replaceAll('_', ' ')}</dt><dd>{value}</dd></React.Fragment>)}</dl><p>Close and reopen are intentionally not exposed until a canonical source-authorized service exists. Missing periods never auto-open.</p></div>
  </MainContent>
</div>;

FinancialPeriodControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default FinancialPeriodControlWorkspace;
