import React, { useMemo, useState } from 'react';
import { useForm, usePage } from '@inertiajs/react';
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
import StatusBadge from '../../../Components/Ivorq/primitives/StatusBadge';

declare const route: any;

interface MigrationPlan {
  id: string;
  source_domain: string;
  target_domain: string;
  status: string;
  correlation_id: string;
  cutover_authority: string;
  execution_authority: string;
  dry_run_completed_at: string | null;
  created_actor_id: string;
  created_at: string | null;
  updated_at: string | null;
}

interface Props {
  plans: MigrationPlan[];
  permissions: {
    can_view: boolean;
    can_manage: boolean;
  };
  constants: {
    source_domain: string;
    target_domain: string;
    cutover_not_authorized: string;
    execution_unavailable: string;
  };
}

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/payables/payment-proposals', label: 'Payment Proposals' },
  { href: '/finance/payables/cashbook-evidence', label: 'Cashbook Evidence' },
  { href: '/finance/banking/operations', label: 'Banking Operations' },
  { href: '/finance/banking/migration', label: 'Migration Control' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
];

export default function BankingMigrationControlWorkspace({ plans, permissions, constants }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [showCreateForm, setShowCreateForm] = useState<boolean>(false);

  const planCount = useMemo(() => plans.length, [plans]);
  const draftCount = useMemo(() => plans.filter((p) => p.status === 'DRAFT').length, [plans]);
  const dryRunRequestedCount = useMemo(() => plans.filter((p) => p.status === 'DRY_RUN_REQUESTED').length, [plans]);
  const dryRunCompletedCount = useMemo(() => plans.filter((p) => p.status === 'DRY_RUN_COMPLETED').length, [plans]);
  const blockedCount = useMemo(() => plans.filter((p) => p.status === 'BLOCKED').length, [plans]);

  const canView = permissions?.can_view ?? false;
  const canManage = permissions?.can_manage ?? false;

  const createForm = useForm<{
    request_identity: string;
  }>({
    request_identity: '',
  });

  const submitCreate = (event: React.FormEvent) => {
    event.preventDefault();
    createForm.post(route('finance.banking.migration.plan.create'), {
      preserveScroll: true,
      onSuccess: () => {
        setShowCreateForm(false);
        createForm.reset();
      },
    });
  };

  const requestDryRun = (planId: string) => {
    const form = useForm({});
    form.post(route('finance.banking.migration.plan.request-dry-run', { plan: planId }), {
      preserveScroll: true,
    });
  };

  const statusColor = (status: string): string => {
    switch (status) {
      case 'DRAFT':
        return 'neutral';
      case 'DRY_RUN_REQUESTED':
        return 'inspection-blue';
      case 'DRY_RUN_COMPLETED':
        return 'ready-green';
      case 'BLOCKED':
        return 'critical-red';
      case 'ARCHIVED':
        return 'neutral';
      default:
        return 'neutral';
    }
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title="Banking Migration Control">
        <div style={{ display: 'flex', gap: '8px' }}>
          {canManage && (
            <Button type="button" variant="primary" onClick={() => setShowCreateForm(!showCreateForm)}>
              {showCreateForm ? 'Cancel' : 'New Migration Plan'}
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
                Migration plans are property-scoped. Plans from other properties are not visible here.
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Plan Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Total Plans: {planCount}</div>
                <div>Draft: {draftCount}</div>
                <div>Dry Run Requested: {dryRunRequestedCount}</div>
                <div>Dry Run Completed: {dryRunCompletedCount}</div>
                <div>Blocked: {blockedCount}</div>
              </div>
            </div>
            <div className="filter-group" style={{ borderTop: '1px solid var(--border-default)', paddingTop: '12px' }}>
              <label className="filter-label">Domains</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Source: {constants.source_domain}</div>
                <div>Target: {constants.target_domain}</div>
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Capability</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>View: {canView ? 'Authorized' : 'Unauthorized'}</div>
                <div>Manage: {canManage ? 'Authorized' : 'Unauthorized'}</div>
                <div>Cutover: {constants.cutover_not_authorized}</div>
                <div>Execution: {constants.execution_unavailable}</div>
              </div>
              {!canView && (
                <div className="finance-context-note" style={{ marginTop: '4px' }}>
                  Migration view permission is not assigned to the current actor.
                </div>
              )}
              {!canManage && (
                <div className="finance-context-note" style={{ marginTop: '2px' }}>
                  Migration manage permission is not assigned to the current actor.
                </div>
              )}
            </div>
          </div>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div className={`finance-flash ${flash.success ? 'success' : 'error'}`}>{flash.success || flash.error}</div>
          )}

          {showCreateForm && canManage && (
            <AttentionArea title="Create Migration Plan" badgeText="Control Plane" badgeType="inspection" areaType="inspection">
              <form onSubmit={submitCreate} style={{ display: 'grid', gap: '12px' }}>
                <div className="filter-group">
                  <label className="filter-label">Request Identity</label>
                  <input
                    type="text"
                    value={createForm.data.request_identity}
                    onChange={(e) => createForm.setData('request_identity', e.target.value)}
                    placeholder="Unique request identifier (max 120 characters)"
                    maxLength={120}
                    style={{ width: '100%', padding: '6px 8px' }}
                  />
                  <div className="finance-context-note">
                    Source domain, target domain, property, actor, and authority are server-resolved. Only a request identity is accepted from browser input.
                  </div>
                </div>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <Button type="submit" variant="primary" disabled={createForm.processing}>
                    Create Plan
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setShowCreateForm(false)}>
                    Cancel
                  </Button>
                </div>
              </form>
            </AttentionArea>
          )}

          <OperationalSnapshot>
            <SnapshotCard value={planCount} label="Plans" statusColor="inspection-blue" />
            <SnapshotCard value={draftCount} label="Draft" statusColor="neutral" />
            <SnapshotCard value={dryRunCompletedCount} label="Completed" statusColor="ready-green" />
            <SnapshotCard value={blockedCount} label="Blocked" statusColor="critical-red" />
          </OperationalSnapshot>

          {plans.length === 0 && (
            <AttentionArea title="Migration Control Plane" badgeText="No Plans" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No migration plans exist for the current property.
                {canManage && ' Create a migration plan to begin non-destructive legacy source inventory.'}
              </div>
            </AttentionArea>
          )}

          {plans.length > 0 && (
            <>
              <QueueList title={`Migration Plans (${planCount})`} count={planCount}>
                <div className="finance-queue-body">
                  {plans.map((plan) => (
                    <WorkCard
                      key={plan.id}
                      borderColor={statusColor(plan.status)}
                      meta={
                        <>
                          <span>{plan.created_at || 'Date unavailable'}</span>
                          <StatusBadge status={plan.status === 'DRY_RUN_COMPLETED' ? 'ready' : plan.status === 'BLOCKED' ? 'critical' : plan.status === 'DRY_RUN_REQUESTED' ? 'inspection' : plan.status === 'DRAFT' ? 'neutral' : 'neutral'}>
                            {plan.status}
                          </StatusBadge>
                        </>
                      }
                      title={`Plan ${plan.id}`}
                      detail={
                        <span>
                          Source: {plan.source_domain}
                          <br />
                          Target: {plan.target_domain}
                          <br />
                          Cutover: {plan.cutover_authority}
                          <br />
                          Execution: {plan.execution_authority}
                          {plan.dry_run_completed_at && (
                            <>
                              <br />
                              Dry Run: {plan.dry_run_completed_at}
                            </>
                          )}
                          <br />
                          Correlation: {plan.correlation_id}
                        </span>
                      }
                      actions={
                        canManage && plan.status === 'DRAFT' ? (
                          <Button
                            type="button"
                            size="sm"
                            variant="secondary"
                            onClick={() => {
                              const form = useForm({});
                              form.post(route('finance.banking.migration.plan.request-dry-run', { plan: plan.id }), {
                                preserveScroll: true,
                              });
                            }}
                          >
                            Request Dry Run
                          </Button>
                        ) : (
                          <></>
                        )
                      }
                    />
                  ))}
                </div>
              </QueueList>

              <div style={{ marginTop: '16px', padding: '12px 16px', backgroundColor: 'var(--surface-secondary)', border: '1px solid var(--border-default)', borderRadius: '4px' }}>
                <div style={{ fontSize: '11px', color: 'var(--text-secondary)', fontStyle: 'italic' }}>
                  This is a non-executable migration control plane. No legacy records are modified. No controlled records are created. No mapping, comparison, score, candidate, balance, account number, or financial payload is projected. Cutover authority is always {constants.cutover_not_authorized}.
                </div>
              </div>
            </>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

BankingMigrationControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
