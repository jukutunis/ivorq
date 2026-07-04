import React from 'react';
import { Link, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import Icon from '../../../Components/Ivorq/primitives/Icon';

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

interface Props {
  transactions: Transaction[];
  approved_proposals: Proposal[];
}

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

export default function CashbookEvidenceWorkspace({ transactions, approved_proposals }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  const outflowTotal = transactions
    .filter((t) => t.direction === 'OUTFLOW')
    .reduce((sum, t) => sum + parseFloat(t.amount || '0'), 0);

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / Cashbook Evidence">
        <Link href={route('finance.payables.payment-proposals.index')} preserveScroll className="btn btn-secondary">
          <Icon name="file-text" /> Payment Proposals
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <div style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <div style={{ fontSize: '12px', fontWeight: 600, marginBottom: '4px' }}>
                Summary
              </div>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Cash Transactions: {transactions.length}</div>
                <div>Outflow Total: {outflowTotal.toFixed(2)}</div>
                <div>Approved Proposals: {approved_proposals.length}</div>
              </div>
            </div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div style={{
              border: `1px solid var(--${flash.success ? 'ready-green' : 'critical-red'})`,
              borderRadius: '6px',
              padding: '10px 12px',
              marginBottom: '14px',
              color: `var(--${flash.success ? 'ready-green' : 'critical-red'})`,
              background: 'var(--surface-card)',
              fontSize: '13px',
              fontWeight: 600,
            }}>{flash.success || flash.error}</div>
          )}

          <AttentionArea title="Approved Payment Proposals" badgeText={`${approved_proposals.length}`} badgeType="ready" areaType="inspection">
            {approved_proposals.length === 0 ? (
              <div style={{ color: 'var(--text-secondary)', fontSize: '13px', padding: '12px 0' }}>
                No approved payment proposals for the current property.
              </div>
            ) : (
              <div style={{ display: 'grid', gap: '8px' }}>
                {approved_proposals.map((p) => (
                  <div key={p.id} style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    borderTop: '1px solid var(--border-default)',
                    paddingTop: '8px',
                    fontSize: '13px',
                  }}>
                    <div>
                      <div style={{ fontWeight: 600 }}>{p.proposal_number}</div>
                      <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>{p.vendor_name || '—'}</div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <div style={{ fontWeight: 700 }}>{p.total_amount} {p.currency_code}</div>
                      {p.approved_at && <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>{p.approved_at}</div>}
                    </div>
                  </div>
                ))}
              </div>
            )}
          </AttentionArea>

          <AttentionArea title="Cashbook Transactions" badgeText={`${transactions.length}`} badgeType="inspection" areaType="inspection" style={{ marginTop: '16px' }}>
            {transactions.length === 0 ? (
              <div style={{ color: 'var(--text-secondary)', fontSize: '13px', padding: '12px 0' }}>
                No cashbook transactions recorded for the current property.
              </div>
            ) : (
              <div style={{ display: 'grid', gap: '8px' }}>
                {transactions.map((tx) => (
                  <div key={tx.id} style={{
                    display: 'flex',
                    justifyContent: 'space-between',
                    borderTop: '1px solid var(--border-default)',
                    paddingTop: '8px',
                    fontSize: '13px',
                  }}>
                    <div>
                      <div style={{ fontWeight: 600 }}>
                        {tx.direction === 'OUTFLOW' ? 'Payment' : tx.direction}
                        {tx.journal_reference && ` — ${tx.journal_reference}`}
                      </div>
                      <div style={{ fontSize: '11px', color: 'var(--text-secondary)' }}>
                        {tx.posted_business_date && `Date: ${tx.posted_business_date}`}
                      </div>
                    </div>
                    <div style={{ textAlign: 'right' }}>
                      <div style={{ fontWeight: 700, color: 'var(--critical-red)' }}>
                        -{tx.amount} {tx.currency_code}
                      </div>
                    </div>
                  </div>
                ))}
              </div>
            )}
          </AttentionArea>
        </MainContent>
      </SplitLayout>
    </div>
  );
}

CashbookEvidenceWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
