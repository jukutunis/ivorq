import React, { useMemo, useState } from 'react';
import { Link, router } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';
import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';

type Candidate = { id: string; source_type: string; source_id: string; posting_event: string; status: string; candidate_date: string | null; description: string | null; created_by: string | null; approved_by: string | null; rejected_by: string | null; line_count: number };
type JournalLine = { id: string; account_code: string | null; account_name: string | null; debit_amount: string; credit_amount: string; memo: string | null };
type Journal = { id: string; reference: string; source_module: string | null; source_type: string | null; source_id: string | null; posting_event: string | null; status: string; transaction_date: string | null; posting_date: string | null; description: string | null; candidate_id: string | null; reversal_of_id: string | null; reversed_by_id: string | null; finalized_by: string | null; posted_by: string | null; posted_at: string | null; lines: JournalLine[] };

const tabs = [
  { href: '/finance/general-ledger/operations', label: 'Ledger Operations' },
  { href: '/finance/general-ledger/reports', label: 'Financial Reports' },
  { href: '/finance/general-ledger/period-control', label: 'Period Control' },
  { href: '/finance/fx-rates', label: 'FX Rate Evidence' },
  { href: '/finance/banking/operations', label: 'Banking Evidence' },
];

const GeneralLedgerOperationsWorkspace = ({ candidates, journals, filters, boundaries }: { candidates: Candidate[]; journals: Journal[]; filters: Record<string, string>; boundaries: { grni_route: string; realized_fx_route: string } }) => {
  const [selectedId, setSelectedId] = useState(journals[0]?.id ?? '');
  const [form, setForm] = useState({ q: filters.q ?? '', state: filters.state ?? '', from: filters.from ?? '', to: filters.to ?? '' });
  const selected = useMemo(() => journals.find((item) => item.id === selectedId) ?? journals[0], [journals, selectedId]);
  const applyFilters = (event: React.FormEvent) => { event.preventDefault(); router.get('/finance/general-ledger/operations', form, { preserveState: true, replace: true }); };

  return <div className="workspace">
    <WorkspaceHeader title="General Ledger Operations" />
    <ModuleTabs tabs={tabs} />
    <SplitLayout>
      <QuickFilterPanel>
        <form onSubmit={applyFilters}>
          <div className="filter-group"><label className="filter-label">Search</label><input className="filter-input" value={form.q} onChange={(e) => setForm({ ...form, q: e.target.value })} placeholder="Reference or source" /></div>
          <div className="filter-group"><label className="filter-label">State</label><select className="filter-input" value={form.state} onChange={(e) => setForm({ ...form, state: e.target.value })}><option value="">All</option><option value="PENDING_REVIEW">Candidate · Pending review</option><option value="APPROVED">Candidate · Approved</option><option value="POSTED">Candidate · Posted</option><option value="Draft">Journal · Draft</option><option value="Posted">Journal · Posted</option><option value="Voided">Journal · Voided</option></select></div>
          <div className="filter-group"><label className="filter-label">From</label><input type="date" className="filter-input" value={form.from} onChange={(e) => setForm({ ...form, from: e.target.value })} /></div>
          <div className="filter-group"><label className="filter-label">To</label><input type="date" className="filter-input" value={form.to} onChange={(e) => setForm({ ...form, to: e.target.value })} /></div>
          <button className="btn btn-primary" type="submit">Apply filters</button>
        </form>
        <div style={{ marginTop: 20, fontSize: 12 }}><strong>Controlled actions</strong><p>Mutations stay in their source-specific workflows.</p><Link href={boundaries.grni_route}>Open GRNI control</Link><br /><Link href={boundaries.realized_fx_route}>Open Realized FX control</Link></div>
      </QuickFilterPanel>
      <MainContent>
        <div className="finance-control-grid">
          <section className="work-card"><h3>Candidate queue <span className="badge">{candidates.length}</span></h3><div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Date</th><th>Source</th><th>Event</th><th>Status</th><th>Lines</th></tr></thead><tbody>{candidates.map((item) => <tr key={item.id}><td>{item.candidate_date ?? '—'}</td><td>{item.source_type}<br /><small>{item.source_id}</small></td><td>{item.posting_event}</td><td>{item.status}</td><td>{item.line_count}</td></tr>)}</tbody></table></div></section>
          <section className="work-card"><h3>Journal history <span className="badge">{journals.length}</span></h3><div style={{ overflowX: 'auto' }}><table className="data-table"><thead><tr><th>Date</th><th>Reference</th><th>Source</th><th>Status</th><th>Provenance</th></tr></thead><tbody>{journals.map((item) => <tr key={item.id} onClick={() => setSelectedId(item.id)} style={{ cursor: 'pointer', background: selected?.id === item.id ? 'var(--surface-selected, #eef4ff)' : undefined }}><td>{item.transaction_date ?? '—'}</td><td>{item.reference}</td><td>{item.source_module ?? '—'} / {item.source_type ?? '—'}</td><td>{item.status}</td><td>{item.reversal_of_id ? `Reverses ${item.reversal_of_id}` : item.reversed_by_id ? `Reversed by ${item.reversed_by_id}` : 'Original'}</td></tr>)}</tbody></table></div></section>
          {selected && <aside className="work-card"><h3>{selected.reference}</h3><p>{selected.description}</p><dl><dt>Candidate</dt><dd>{selected.candidate_id ?? 'Legacy/source journal'}</dd><dt>Finalized by</dt><dd>{selected.finalized_by ?? '—'}</dd><dt>Posted by</dt><dd>{selected.posted_by ?? '—'}</dd></dl><table className="data-table"><thead><tr><th>Account</th><th>Debit</th><th>Credit</th></tr></thead><tbody>{selected.lines.map((line) => <tr key={line.id}><td>{line.account_code} · {line.account_name}</td><td>{line.debit_amount}</td><td>{line.credit_amount}</td></tr>)}</tbody></table></aside>}
        </div>
      </MainContent>
    </SplitLayout>
  </div>;
};

GeneralLedgerOperationsWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
export default GeneralLedgerOperationsWorkspace;
