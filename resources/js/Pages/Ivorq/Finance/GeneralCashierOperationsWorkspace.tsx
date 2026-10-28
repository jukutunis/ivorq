import React from 'react';
import { useForm, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';
import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import OperationalSnapshot from '../../../Components/Ivorq/patterns/OperationalSnapshot';
import SnapshotCard from '../../../Components/Ivorq/patterns/SnapshotCard';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

declare const route: any;

type Session = { id: string; status: string; opened_at: string | null };
type Instrument = { id: string; name: string; type: string; operational_gl_account_id: string; operational_account?: { code: string; name: string } };
type Account = { id: string; code: string; name: string; is_cash_equivalent: boolean };
type Count = { id: string; operational_gl_account_id: string; currency_code: string; observed_amount: string; observed_count_date: string; source_reference: string };
type Baseline = { id: string; cash_count_evidence_id: string; currency_code: string; baseline_amount: string; cashbook_boundary_posted_business_date: string };
type CorrectionProjection = {
  executions: Array<{ id: string; amount: string; currency_code: string; executed_at: string | null; instrument_type: string | null; voided: boolean }>;
  posted_cash_journals: Array<{ id: string; reference: string | null; posting_date: string | null; source_id: string }>;
  cash_returns: Array<{ id: string; payment_execution_id: string; amount: string; currency_code: string; source_reference: string; observed_return_date: string; reversed: boolean }>;
  reversals: Array<{ id: string; cash_return_evidence_id: string; amount: string; currency_code: string; candidate_id: string | null; candidate_status: string | null }>;
  bank_return_lines: Array<{ id: string; controlled_bank_account_id: string; amount: string; currency_code: string; statement_date: string; external_reference: string }>;
  bank_returns: Array<{ id: string; payment_execution_id: string; amount: string; currency_code: string; reason_code: string; return_statement_line_id: string; reversed: boolean }>;
  bank_reversals: Array<{ id: string; bank_return_evidence_id: string; amount: string; currency_code: string; candidate_id: string | null; candidate_status: string | null }>;
};
type Permissions = { can_open_session: boolean; can_configure_instrument: boolean; can_record_count: boolean; can_create_baseline: boolean; can_void: boolean; can_record_cash_return: boolean; can_reverse_cash: boolean; can_record_bank_return: boolean; can_reverse_bank: boolean };

const tabs = [
  { href: '/finance/payables/supplier-invoices', label: 'Supplier Invoices' },
  { href: '/finance/general-cashier/operations', label: 'General Cashier' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/banking/operations', label: 'Banking Operations' },
];

export default function GeneralCashierOperationsWorkspace({ sessions, instruments, accounts, cash_counts, baselines, corrections, permissions }: {
  sessions: Session[]; instruments: Instrument[]; accounts: Account[]; cash_counts: Count[]; baselines: Baseline[]; corrections: CorrectionProjection; permissions: Permissions;
}) {
  const { flash } = usePage<{ flash?: { success?: string; error?: string } }>().props;
  const openForm = useForm({});

  return <div className="workspace">
    <WorkspaceHeader title="General Cashier Operations" />
    <ModuleTabs tabs={tabs} />
    <MainContent>
      {(flash?.success || flash?.error) && <div className={`finance-flash ${flash.success ? 'success' : 'error'}`}>{flash.success || flash.error}</div>}
      <OperationalSnapshot>
        <SnapshotCard value={sessions.filter((item) => item.status === 'OPEN').length} label="Open Sessions" statusColor="ready-green" />
        <SnapshotCard value={instruments.filter((item) => item.type === 'CASH').length} label="Cash Instruments" statusColor="inspection-blue" />
        <SnapshotCard value={cash_counts.length} label="Count Evidence" statusColor="pending-purple" />
        <SnapshotCard value={corrections.reversals.length} label="Correction Chains" statusColor="critical-red" />
      </OperationalSnapshot>

      <div className="finance-master-detail">
        <AttentionArea title="Session and Instrument Setup" badgeText="Operations-owned" badgeType="inspection" areaType="inspection">
          <p className="finance-context-note">Sessions and instruments remain General Cashier records. Account identity is re-resolved and property-validated by the server.</p>
          {permissions.can_open_session && <Button type="button" onClick={() => openForm.post(route('finance.general-cashier.sessions.open'))} disabled={openForm.processing}><Icon name="check" /> Open My Session</Button>}
          {permissions.can_configure_instrument && <InstrumentForm accounts={accounts} />}
          <div className="finance-evidence-section">
            <div className="finance-section-title">Active Operational Context</div>
            <div className="finance-evidence-list">
              {sessions.map((session) => <EvidenceRow key={session.id} label={`Session ${session.id}`} value={`${session.status} · ${session.opened_at ?? 'time unavailable'}`} />)}
              {instruments.map((instrument) => <EvidenceRow key={instrument.id} label={instrument.name} value={`${instrument.type} · ${instrument.operational_account?.code ?? instrument.operational_gl_account_id}`} />)}
            </div>
          </div>
        </AttentionArea>

        <AttentionArea title="Count and Reconciliation Baseline" badgeText="Immutable Evidence" badgeType="pending" areaType="inspection">
          {permissions.can_record_count && <CashCountForm accounts={accounts.filter((account) => account.is_cash_equivalent)} />}
          {permissions.can_create_baseline && <BaselineForm counts={cash_counts} />}
          <div className="finance-evidence-list">
            {cash_counts.map((count) => <EvidenceRow key={count.id} label={count.source_reference} value={`${count.currency_code} ${count.observed_amount} · ${count.observed_count_date}`} />)}
            {baselines.map((baseline) => <EvidenceRow key={baseline.id} label={`Baseline ${baseline.id}`} value={`${baseline.currency_code} ${baseline.baseline_amount} · ${baseline.cashbook_boundary_posted_business_date}`} />)}
          </div>
        </AttentionArea>
      </div>

      <PaymentCorrectionPanel corrections={corrections} permissions={permissions} />
    </MainContent>
  </div>;
}

function InstrumentForm({ accounts }: { accounts: Account[] }) {
  const form = useForm({ name: '', type: 'CASH', operational_gl_account_id: '' });
  return <form className="finance-evidence-section" onSubmit={(event) => { event.preventDefault(); form.post(route('finance.general-cashier.instruments.store')); }}>
    <div className="finance-section-title">Configure Payment Instrument</div>
    <div className="finance-evidence-grid">
      <input className="form-input" placeholder="Business name" value={form.data.name} onChange={(e) => form.setData('name', e.target.value)} required />
      <select className="form-input" value={form.data.type} onChange={(e) => form.setData('type', e.target.value)}><option value="CASH">Cash</option><option value="BANK">Bank</option></select>
      <select className="form-input" value={form.data.operational_gl_account_id} onChange={(e) => form.setData('operational_gl_account_id', e.target.value)} required><option value="">Select validated account</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} — {account.name}</option>)}</select>
    </div>
    <Button type="submit" disabled={form.processing}>Configure</Button>
  </form>;
}

function CashCountForm({ accounts }: { accounts: Account[] }) {
  const form = useForm({ operational_gl_account_id: '', currency_code: 'IDR', observed_amount: '', observed_count_date: new Date().toISOString().slice(0, 10), source_reference: '' });
  return <form onSubmit={(event) => { event.preventDefault(); form.post(route('finance.general-cashier.cash-counts.store')); }}>
    <div className="finance-evidence-grid">
      <select className="form-input" value={form.data.operational_gl_account_id} onChange={(e) => form.setData('operational_gl_account_id', e.target.value)} required><option value="">Cash account</option>{accounts.map((account) => <option key={account.id} value={account.id}>{account.code} — {account.name}</option>)}</select>
      <input className="form-input" value={form.data.currency_code} onChange={(e) => form.setData('currency_code', e.target.value.toUpperCase())} maxLength={3} required />
      <input type="number" min="0" step="0.01" className="form-input" placeholder="Observed physical amount" value={form.data.observed_amount} onChange={(e) => form.setData('observed_amount', e.target.value)} required />
      <input type="date" className="form-input" value={form.data.observed_count_date} onChange={(e) => form.setData('observed_count_date', e.target.value)} required />
      <input className="form-input" placeholder="Independent source reference" value={form.data.source_reference} onChange={(e) => form.setData('source_reference', e.target.value)} required />
    </div>
    <Button type="submit" disabled={form.processing}>Record Cash Count</Button>
  </form>;
}

function BaselineForm({ counts }: { counts: Count[] }) {
  const form = useForm({ cash_count_evidence_id: '' });
  return <form style={{ display: 'flex', gap: 8, marginTop: 12 }} onSubmit={(event) => { event.preventDefault(); form.post(route('finance.general-cashier.baselines.store')); }}>
    <select className="form-input" value={form.data.cash_count_evidence_id} onChange={(e) => form.setData('cash_count_evidence_id', e.target.value)} required><option value="">Select count evidence</option>{counts.map((count) => <option key={count.id} value={count.id}>{count.source_reference} — {count.currency_code} {count.observed_amount}</option>)}</select>
    <Button type="submit" disabled={form.processing}>Create Baseline</Button>
  </form>;
}

function PaymentCorrectionPanel({ corrections, permissions }: { corrections: CorrectionProjection; permissions: Permissions }) {
  const voidForm = useForm({ payment_execution_id: '', reason: '' });
  const returnForm = useForm({ posted_journal_entry_id: '', source_reference: '', observed_return_date: new Date().toISOString().slice(0, 10) });
  const reversalForm = useForm({ cash_return_evidence_id: '' });
  const bankReturnForm = useForm({ posted_journal_entry_id: '', return_statement_line_id: '', reason_code: 'BANK_RETURN', idempotency_key: crypto.randomUUID() });
  const bankReversalForm = useForm({ bank_return_evidence_id: '' });
  return <AttentionArea title="Supplier Payment Correction" badgeText="Confirmation Required" badgeType="warning" areaType="warning">
    <p className="finance-context-note">Pre-post VOID and posted cash reversal are separate. Posted reversal requires independently recorded cash-return evidence and creates a pending-review GL candidate; it never rewrites the original payment or journal.</p>
    <div className="finance-master-detail">
      {permissions.can_void && <form onSubmit={(event) => { event.preventDefault(); voidForm.post(route('finance.general-cashier.corrections.void')); }}>
        <div className="finance-section-title">Eligible Pre-post VOID</div>
        <select className="form-input" value={voidForm.data.payment_execution_id} onChange={(e) => voidForm.setData('payment_execution_id', e.target.value)} required><option value="">Select execution</option>{corrections.executions.filter((item) => !item.voided).map((item) => <option key={item.id} value={item.id}>{item.id} — {item.currency_code} {item.amount}</option>)}</select>
        <input className="form-input" placeholder="Controlled reason" value={voidForm.data.reason} onChange={(e) => voidForm.setData('reason', e.target.value)} required />
        <Button type="submit" disabled={voidForm.processing}>Record VOID Evidence</Button>
      </form>}
      {permissions.can_record_cash_return && <form onSubmit={(event) => { event.preventDefault(); returnForm.post(route('finance.general-cashier.corrections.cash-return')); }}>
        <div className="finance-section-title">Observed Cash Return</div>
        <select className="form-input" value={returnForm.data.posted_journal_entry_id} onChange={(e) => returnForm.setData('posted_journal_entry_id', e.target.value)} required><option value="">Select posted payment journal</option>{corrections.posted_cash_journals.map((item) => <option key={item.id} value={item.id}>{item.reference ?? item.id} — {item.posting_date}</option>)}</select>
        <input className="form-input" placeholder="Independent cash-return reference" value={returnForm.data.source_reference} onChange={(e) => returnForm.setData('source_reference', e.target.value)} required />
        <input type="date" className="form-input" value={returnForm.data.observed_return_date} onChange={(e) => returnForm.setData('observed_return_date', e.target.value)} required />
        <Button type="submit" disabled={returnForm.processing}>Record Return Evidence</Button>
      </form>}
      {permissions.can_reverse_cash && <form onSubmit={(event) => { event.preventDefault(); reversalForm.post(route('finance.general-cashier.corrections.cash-reversal')); }}>
        <div className="finance-section-title">Create Cash Reversal Chain</div>
        <select className="form-input" value={reversalForm.data.cash_return_evidence_id} onChange={(e) => reversalForm.setData('cash_return_evidence_id', e.target.value)} required><option value="">Select unreversed return</option>{corrections.cash_returns.filter((item) => !item.reversed).map((item) => <option key={item.id} value={item.id}>{item.source_reference} — {item.currency_code} {item.amount}</option>)}</select>
        <Button type="submit" disabled={reversalForm.processing}>Create Reversal + GL Candidate</Button>
      </form>}
      {permissions.can_record_bank_return && <form onSubmit={(event) => { event.preventDefault(); bankReturnForm.post(route('finance.general-cashier.corrections.bank-return'), { onSuccess: () => bankReturnForm.setData('idempotency_key', crypto.randomUUID()) }); }}>
        <div className="finance-section-title">Banking-owned Return Evidence</div>
        <select className="form-input" value={bankReturnForm.data.posted_journal_entry_id} onChange={(e) => bankReturnForm.setData('posted_journal_entry_id', e.target.value)} required><option value="">Select posted bank-payment journal</option>{corrections.posted_cash_journals.map((item) => <option key={item.id} value={item.id}>{item.reference ?? item.id} — {item.posting_date}</option>)}</select>
        <select className="form-input" value={bankReturnForm.data.return_statement_line_id} onChange={(e) => bankReturnForm.setData('return_statement_line_id', e.target.value)} required><option value="">Select independent inflow evidence</option>{corrections.bank_return_lines.map((item) => <option key={item.id} value={item.id}>{item.external_reference} — {item.currency_code} {item.amount}</option>)}</select>
        <select className="form-input" value={bankReturnForm.data.reason_code} onChange={(e) => bankReturnForm.setData('reason_code', e.target.value)}><option value="BANK_RETURN">Bank Return</option><option value="PAYMENT_REJECTED">Payment Rejected</option><option value="BENEFICIARY_REJECTED">Beneficiary Rejected</option></select>
        <Button type="submit" disabled={bankReturnForm.processing}>Record Bank Return</Button>
      </form>}
      {permissions.can_reverse_bank && <form onSubmit={(event) => { event.preventDefault(); bankReversalForm.post(route('finance.general-cashier.corrections.bank-reversal')); }}>
        <div className="finance-section-title">Create Bank Reversal Chain</div>
        <select className="form-input" value={bankReversalForm.data.bank_return_evidence_id} onChange={(e) => bankReversalForm.setData('bank_return_evidence_id', e.target.value)} required><option value="">Select unreversed bank return</option>{corrections.bank_returns.filter((item) => !item.reversed).map((item) => <option key={item.id} value={item.id}>{item.reason_code} — {item.currency_code} {item.amount}</option>)}</select>
        <Button type="submit" disabled={bankReversalForm.processing}>Create Bank Reversal + GL Candidate</Button>
      </form>}
    </div>
    <div className="finance-evidence-section"><div className="finance-section-title">Read-only Correction Provenance</div><div className="finance-evidence-list">{corrections.reversals.map((item) => <EvidenceRow key={item.id} label={`Cash reversal ${item.id}`} value={`${item.currency_code} ${item.amount} · candidate ${item.candidate_id ?? 'not created'} · ${item.candidate_status ?? 'N/A'}`} />)}{corrections.bank_reversals.map((item) => <EvidenceRow key={item.id} label={`Bank reversal ${item.id}`} value={`${item.currency_code} ${item.amount} · candidate ${item.candidate_id ?? 'not created'} · ${item.candidate_status ?? 'N/A'}`} />)}</div></div>
  </AttentionArea>;
}

function EvidenceRow({ label, value }: { label: string; value: string }) {
  return <div className="finance-evidence-row"><span>{label}</span><span>{value}</span></div>;
}

GeneralCashierOperationsWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
