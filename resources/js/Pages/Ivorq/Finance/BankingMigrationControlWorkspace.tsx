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

interface TargetIntake {
  id: string;
  migration_plan_id: string;
  manifest_entry_id: string;
  source_domain: string;
  source_model: string;
  source_category: string | null;
  target_domain: string;
  target_model: string;
  controlled_bank_account_id: string;
  status: string;
  correlation_id: string;
  proposal_actor_id: string;
  review_actor_id: string | null;
  review_outcome: string | null;
  review_timestamp: string | null;
  execution_authority: string;
  cutover_authority: string;
  created_at: string | null;
  updated_at: string | null;
}

interface PilotAuthorization {
  id: string;
  migration_plan_id: string;
  manifest_entry_id: string;
  target_intake_id: string;
  authorization_scope: string;
  status: string;
  correlation_id: string;
  request_actor_id: string;
  review_actor_id: string | null;
  review_outcome: string | null;
  review_timestamp: string | null;
  execution_authority: string;
  cutover_authority: string;
  created_at: string | null;
  updated_at: string | null;
}

interface ProposalContext {
  eligible_plans: Array<{
    id: string;
    status: string;
    source_domain: string;
    target_domain: string;
    correlation_id: string;
  }>;
  eligible_manifest_entries: Array<{
    id: string;
    migration_plan_id: string;
    source_domain: string;
    source_model: string;
    inventory_status: string;
  }>;
  available_controlled_accounts: Array<{
    id: string;
    account_name: string;
    bank_name: string;
  }>;
}

interface ExecutionPrecondition {
  pilot_authorization_id: string;
  pilot_authorization_status: string;
  manifest_source_scope: string;
  manifest_source_snapshot: string;
  exception_quarantine_state: string;
  target_intake_review_state: string;
  pilot_auth_review_state: string;
  target_operational_state: string;
  property_boundary: string;
  future_lineage_contract: string;
  future_execution_permission: string;
  future_cutover_permission: string;
  summary_status: string;
}

interface Props {
  plans: MigrationPlan[];
  target_intakes: TargetIntake[];
  pilot_authorizations: PilotAuthorization[];
  proposal_context: ProposalContext;
  execution_preconditions: ExecutionPrecondition[];
  permissions: {
    can_view: boolean;
    can_manage: boolean;
    can_review: boolean;
    can_review_pilot_auth: boolean;
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

export default function BankingMigrationControlWorkspace({ plans, target_intakes, pilot_authorizations = [], proposal_context, execution_preconditions = [], permissions, constants }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const [showCreateForm, setShowCreateForm] = useState<boolean>(false);
  const [showProposeForm, setShowProposeForm] = useState<boolean>(false);
  const [showRequestPilotForm, setShowRequestPilotForm] = useState<boolean>(false);

  const planCount = useMemo(() => plans.length, [plans]);
  const draftCount = useMemo(() => plans.filter((p) => p.status === 'DRAFT').length, [plans]);
  const dryRunRequestedCount = useMemo(() => plans.filter((p) => p.status === 'DRY_RUN_REQUESTED').length, [plans]);
  const dryRunCompletedCount = useMemo(() => plans.filter((p) => p.status === 'DRY_RUN_COMPLETED').length, [plans]);
  const blockedCount = useMemo(() => plans.filter((p) => p.status === 'BLOCKED').length, [plans]);

  const canView = permissions?.can_view ?? false;
  const canManage = permissions?.can_manage ?? false;
  const canReview = permissions?.can_review ?? false;
  const canReviewPilotAuth = permissions?.can_review_pilot_auth ?? false;

  const targetIntakeCount = useMemo(() => target_intakes?.length ?? 0, [target_intakes]);
  const proposedCount = useMemo(() => target_intakes?.filter((t) => t.status === 'PROPOSED').length ?? 0, [target_intakes]);
  const acceptedCount = useMemo(() => target_intakes?.filter((t) => t.status === 'REVIEW_ACCEPTED').length ?? 0, [target_intakes]);
  const rejectedCount = useMemo(() => target_intakes?.filter((t) => t.status === 'REVIEW_REJECTED').length ?? 0, [target_intakes]);

  const pilotAuthCount = useMemo(() => pilot_authorizations?.length ?? 0, [pilot_authorizations]);
  const pilotAuthRequestedCount = useMemo(() => pilot_authorizations?.filter((p) => p.status === 'REQUESTED').length ?? 0, [pilot_authorizations]);
  const pilotAuthAcceptedCount = useMemo(() => pilot_authorizations?.filter((p) => p.status === 'REVIEW_ACCEPTED').length ?? 0, [pilot_authorizations]);
  const pilotAuthRejectedCount = useMemo(() => pilot_authorizations?.filter((p) => p.status === 'REVIEW_REJECTED').length ?? 0, [pilot_authorizations]);

  const createForm = useForm<{
    request_identity: string;
  }>({
    request_identity: '',
  });

  const proposeForm = useForm<{
    banking_migration_plan_id: string;
    banking_migration_manifest_entry_id: string;
    controlled_bank_account_id: string;
  }>({
    banking_migration_plan_id: '',
    banking_migration_manifest_entry_id: '',
    controlled_bank_account_id: '',
  });

  const requestPilotForm = useForm<{
    banking_migration_target_intake_id: string;
  }>({
    banking_migration_target_intake_id: '',
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

  const submitPropose = (event: React.FormEvent) => {
    event.preventDefault();
    proposeForm.post(route('finance.banking.migration.target-intake.propose'), {
      preserveScroll: true,
      onSuccess: () => {
        setShowProposeForm(false);
        proposeForm.reset();
      },
    });
  };

  const submitRequestPilot = (event: React.FormEvent) => {
    event.preventDefault();
    requestPilotForm.post(route('finance.banking.migration.pilot-authorization.request'), {
      preserveScroll: true,
      onSuccess: () => {
        setShowRequestPilotForm(false);
        requestPilotForm.reset();
      },
    });
  };

  const submitPilotReview = (pilotAuthId: string, outcome: string) => {
    const form = useForm<{ review_outcome: string }>({ review_outcome: outcome });
    form.post(route('finance.banking.migration.pilot-authorization.review', { pilotAuth: pilotAuthId }), {
      preserveScroll: true,
    });
  };

  const submitReview = (intakeId: string, outcome: string) => {
    const form = useForm<{ review_outcome: string }>({ review_outcome: outcome });
    form.post(route('finance.banking.migration.target-intake.review', { intake: intakeId }), {
      preserveScroll: true,
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
            <>
              <Button type="button" variant="primary" onClick={() => setShowCreateForm(!showCreateForm)}>
                {showCreateForm ? 'Cancel' : 'New Migration Plan'}
              </Button>
              <Button type="button" variant="primary" onClick={() => setShowProposeForm(!showProposeForm)}>
                {showProposeForm ? 'Cancel' : 'Propose Mapping'}
              </Button>
              <Button type="button" variant="primary" onClick={() => setShowRequestPilotForm(!showRequestPilotForm)}>
                {showRequestPilotForm ? 'Cancel' : 'Request Pilot Authorization'}
              </Button>
            </>
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
            <div className="filter-group">
              <label className="filter-label">Mapping Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Total Intakes: {targetIntakeCount}</div>
                <div>Proposed: {proposedCount}</div>
                <div>Accepted: {acceptedCount}</div>
                <div>Rejected: {rejectedCount}</div>
              </div>
            </div>
            <div className="filter-group">
              <label className="filter-label">Pilot Authorization Summary</label>
              <div style={{ fontSize: '11px', color: 'var(--text-secondary)', lineHeight: '1.6' }}>
                <div>Total Authorizations: {pilotAuthCount}</div>
                <div>Requested: {pilotAuthRequestedCount}</div>
                <div>Review Accepted: {pilotAuthAcceptedCount}</div>
                <div>Review Rejected: {pilotAuthRejectedCount}</div>
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
                <div>Propose: {canManage ? 'Authorized' : 'Unauthorized'}</div>
                <div>Mapping Review: {canReview ? 'Authorized' : 'Unauthorized'}</div>
                <div>Pilot Auth Review: {canReviewPilotAuth ? 'Authorized' : 'Unauthorized'}</div>
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

          {showProposeForm && canManage && proposal_context?.eligible_manifest_entries && (
            <AttentionArea title="Propose Account-Level Mapping" badgeText="Control Plane" badgeType="inspection" areaType="inspection">
              <form onSubmit={submitPropose} style={{ display: 'grid', gap: '12px' }}>
                <div className="filter-group">
                  <label className="filter-label">Migration Plan</label>
                  <select
                    value={proposeForm.data.banking_migration_plan_id}
                    onChange={(e) => proposeForm.setData('banking_migration_plan_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select a plan...</option>
                    {proposal_context.eligible_plans.map((p) => (
                      <option key={p.id} value={p.id}>Plan {p.id} ({p.status})</option>
                    ))}
                  </select>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Manifest Entry (Source)</label>
                  <select
                    value={proposeForm.data.banking_migration_manifest_entry_id}
                    onChange={(e) => proposeForm.setData('banking_migration_manifest_entry_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select a manifest entry...</option>
                    {proposal_context.eligible_manifest_entries
                      .filter((e) => e.migration_plan_id === proposeForm.data.banking_migration_plan_id)
                      .map((e) => (
                        <option key={e.id} value={e.id}>Entry {e.id} ({e.source_model}, {e.inventory_status})</option>
                      ))}
                  </select>
                  <div className="finance-context-note">
                    Only BankAccount manifest entries with INVENTORIED status are eligible. Source is always legacy_banking.
                  </div>
                </div>
                <div className="filter-group">
                  <label className="filter-label">Controlled Bank Account (Target)</label>
                  <select
                    value={proposeForm.data.controlled_bank_account_id}
                    onChange={(e) => proposeForm.setData('controlled_bank_account_id', e.target.value)}
                    style={{ width: '100%', padding: '6px 8px' }}
                  >
                    <option value="">Select a controlled account...</option>
                    {proposal_context.available_controlled_accounts.map((a) => (
                      <option key={a.id} value={a.id}>{a.account_name} ({a.bank_name})</option>
                    ))}
                  </select>
                  <div className="finance-context-note">
                    Target must be an existing active controlled bank account in the current property. No target is suggested, ranked, or auto-selected. No legacy field is compared to target fields.
                  </div>
                </div>
                <div className="finance-context-note">
                  Property, source domain, target domain, actor, authority, and audit evidence are server-resolved. Only plan, manifest entry, and controlled account IDs are accepted from browser input.
                </div>
                <div style={{ display: 'flex', gap: '8px' }}>
                  <Button type="submit" variant="primary" disabled={proposeForm.processing}>
                    Propose Mapping
                  </Button>
                  <Button type="button" variant="secondary" onClick={() => setShowProposeForm(false)}>
                    Cancel
                  </Button>
                </div>
              </form>
            </AttentionArea>
          )}

          {showRequestPilotForm && canManage && target_intakes && (
            (() => {
              const eligibleIntakes = target_intakes.filter(
                (t) => t.status === 'REVIEW_ACCEPTED' && t.source_category === 'bank_account'
              );
              const activePilotAuthTargetIntakeIds = pilot_authorizations
                ?.filter((p) => p.status === 'REQUESTED' || p.status === 'REVIEW_ACCEPTED')
                ?.map((p) => p.target_intake_id) ?? [];
              const currentlyEligible = eligibleIntakes.filter(
                (t) => !activePilotAuthTargetIntakeIds.includes(t.id)
              );

              if (currentlyEligible.length === 0) {
                return (
                  <AttentionArea title="Request Pilot Authorization" badgeText="No Eligible Intakes" badgeType="neutral" areaType="inspection">
                    <div className="finance-empty-state">
                      No REVIEW_ACCEPTED target intakes are currently eligible for pilot authorization request.
                      Each target intake must have source model BankAccount and no active pilot authorization.
                    </div>
                  </AttentionArea>
                );
              }

              return (
                <AttentionArea title="Request Pilot Authorization" badgeText="Control Plane" badgeType="inspection" areaType="inspection">
                  <form onSubmit={submitRequestPilot} style={{ display: 'grid', gap: '12px' }}>
                    <div className="filter-group">
                      <label className="filter-label">Target Intake (REVIEW_ACCEPTED, BankAccount source)</label>
                      <select
                        value={requestPilotForm.data.banking_migration_target_intake_id}
                        onChange={(e) => requestPilotForm.setData('banking_migration_target_intake_id', e.target.value)}
                        style={{ width: '100%', padding: '6px 8px' }}
                      >
                        <option value="">Select an eligible target intake...</option>
                        {currentlyEligible.map((t) => (
                          <option key={t.id} value={t.id}>
                            Intake {t.id} (Plan: {t.migration_plan_id})
                          </option>
                        ))}
                      </select>
                      <div className="finance-context-note">
                        Only REVIEW_ACCEPTED target intakes with BankAccount source are eligible. No legacy account numbers, target account numbers, balances, amounts, or financial payloads are submitted.
                      </div>
                    </div>
                    <div className="finance-context-note">
                      Property, plan, manifest entry, source model, actor, authority, and audit evidence are server-resolved. Only target intake ID is accepted from browser input. Request and review are independent control-plane governance actions.
                    </div>
                    <div style={{ display: 'flex', gap: '8px' }}>
                      <Button type="submit" variant="primary" disabled={requestPilotForm.processing}>
                        Request Pilot Authorization
                      </Button>
                      <Button type="button" variant="secondary" onClick={() => setShowRequestPilotForm(false)}>
                        Cancel
                      </Button>
                    </div>
                  </form>
                </AttentionArea>
              );
            })()
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

          {canView && (() => {
            const intakeStatusColor = (status: string): string => {
              switch (status) {
                case 'PROPOSED': return 'inspection-blue';
                case 'REVIEW_ACCEPTED': return 'ready-green';
                case 'REVIEW_REJECTED': return 'critical-red';
                case 'ARCHIVED': return 'neutral';
                default: return 'neutral';
              }
            };

            const intakeStatusBadge = (status: string): string => {
              switch (status) {
                case 'REVIEW_ACCEPTED': return 'ready';
                case 'REVIEW_REJECTED': return 'critical';
                case 'PROPOSED': return 'inspection';
                default: return 'neutral';
              }
            };

            return (
              <>
                <OperationalSnapshot>
                  <SnapshotCard value={targetIntakeCount} label="Target Intakes" statusColor="inspection-blue" />
                  <SnapshotCard value={proposedCount} label="Proposed" statusColor="inspection-blue" />
                  <SnapshotCard value={acceptedCount} label="Accepted" statusColor="ready-green" />
                  <SnapshotCard value={rejectedCount} label="Rejected" statusColor="critical-red" />
                </OperationalSnapshot>

                {targetIntakeCount === 0 && (
                  <AttentionArea title="Target-Intake Mapping" badgeText="No Mappings" badgeType="neutral" areaType="neutral">
                    <div className="finance-empty-state">
                      No target-intake mapping proposals exist for the current property.
                      Account-level mapping proposals record human-governed planning decisions. No data-plane migration is authorized.
                    </div>
                  </AttentionArea>
                )}

                {targetIntakeCount > 0 && (
                  <>
                    <QueueList title={`Target Intakes (${targetIntakeCount})`} count={targetIntakeCount}>
                      <div className="finance-queue-body">
                        {target_intakes.map((intake) => (
                          <WorkCard
                            key={intake.id}
                            borderColor={intakeStatusColor(intake.status)}
                            meta={
                              <>
                                <span>{intake.created_at || 'Date unavailable'}</span>
                                <StatusBadge status={intakeStatusBadge(intake.status) as any}>
                                  {intake.status}
                                </StatusBadge>
                              </>
                            }
                            title={`Intake ${intake.id}`}
                            detail={
                              <span>
                                Source: {intake.source_domain} / {intake.source_category || intake.source_model}
                                <br />
                                Target: {intake.target_domain} / {intake.target_model}
                                <br />
                                Plan: {intake.migration_plan_id}
                                <br />
                                Manifest: {intake.manifest_entry_id}
                                <br />
                                Cutover: {intake.cutover_authority}
                                <br />
                                Execution: {intake.execution_authority}
                                {intake.review_outcome && (
                                  <>
                                    <br />
                                    Review: {intake.review_outcome} ({intake.review_timestamp || 'timestamp unavailable'})
                                  </>
                                )}
                                <br />
                                Correlation: {intake.correlation_id}
                              </span>
                            }
                            actions={
                              canReview && intake.status === 'PROPOSED' ? (
                                <div style={{ display: 'flex', gap: '4px' }}>
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="primary"
                                    onClick={() => submitReview(intake.id, 'REVIEW_ACCEPTED')}
                                  >
                                    Accept
                                  </Button>
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => submitReview(intake.id, 'REVIEW_REJECTED')}
                                  >
                                    Reject
                                  </Button>
                                </div>
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
                        Target-intake mapping proposals are non-executable control-plane planning records. No legacy account numbers, target account numbers, balances, amounts, currencies, external references, or financial payloads are projected. No mapping score, confidence, or candidate ranking exists. Review-accepted proposals remain MIGRATION_EXECUTION_NOT_AUTHORIZED and CUTOVER_NOT_AUTHORIZED.
                      </div>
                    </div>
                  </>
                )}
              </>
            );
          })()}
          {canView && pilot_authorizations && (() => {
            const pilotAuthCount = pilot_authorizations.length;
            const pilotRequestedCount = pilotAuthRequestedCount;
            const pilotAcceptedCount = pilotAuthAcceptedCount;
            const pilotRejectedCount = pilotAuthRejectedCount;

            const pilotAuthStatusColor = (status: string): string => {
              switch (status) {
                case 'REQUESTED': return 'inspection-blue';
                case 'REVIEW_ACCEPTED': return 'ready-green';
                case 'REVIEW_REJECTED': return 'critical-red';
                case 'ARCHIVED': return 'neutral';
                default: return 'neutral';
              }
            };

            const pilotAuthStatusBadge = (status: string): string => {
              switch (status) {
                case 'REVIEW_ACCEPTED': return 'ready';
                case 'REVIEW_REJECTED': return 'critical';
                case 'REQUESTED': return 'inspection';
                default: return 'neutral';
              }
            };

            return (
              <>
                <OperationalSnapshot>
                  <SnapshotCard value={pilotAuthCount} label="Pilot Authorizations" statusColor="inspection-blue" />
                  <SnapshotCard value={pilotRequestedCount} label="Requested" statusColor="inspection-blue" />
                  <SnapshotCard value={pilotAcceptedCount} label="Review Accepted" statusColor="ready-green" />
                  <SnapshotCard value={pilotRejectedCount} label="Review Rejected" statusColor="critical-red" />
                </OperationalSnapshot>

                {pilotAuthCount === 0 && (
                  <AttentionArea title="Pilot Authorization" badgeText="No Authorizations" badgeType="neutral" areaType="neutral">
                    <div className="finance-empty-state">
                      No pilot authorization records exist for the current property.
                      Pilot authorization is a control-plane governance record. No data-plane migration is authorized.
                    </div>
                  </AttentionArea>
                )}

                {pilotAuthCount > 0 && (
                  <>
                    <QueueList title={`Pilot Authorizations (${pilotAuthCount})`} count={pilotAuthCount}>
                      <div className="finance-queue-body">
                        {pilot_authorizations.map((auth) => (
                          <WorkCard
                            key={auth.id}
                            borderColor={pilotAuthStatusColor(auth.status)}
                            meta={
                              <>
                                <span>{auth.created_at || 'Date unavailable'}</span>
                                <StatusBadge status={pilotAuthStatusBadge(auth.status) as any}>
                                  {auth.status}
                                </StatusBadge>
                              </>
                            }
                            title={`Authorization ${auth.id}`}
                            detail={
                              <span>
                                Scope: {auth.authorization_scope}
                                <br />
                                Target Intake: {auth.target_intake_id}
                                <br />
                                Plan: {auth.migration_plan_id}
                                <br />
                                Migration Execution: {auth.execution_authority}
                                <br />
                                Cutover: {auth.cutover_authority}
                                {auth.review_outcome && (
                                  <>
                                    <br />
                                    Review: {auth.review_outcome} ({auth.review_timestamp || 'timestamp unavailable'})
                                  </>
                                )}
                                <br />
                                Correlation: {auth.correlation_id}
                              </span>
                            }
                            actions={
                              canReviewPilotAuth && auth.status === 'REQUESTED' ? (
                                <div style={{ display: 'flex', gap: '4px' }}>
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="primary"
                                    onClick={() => submitPilotReview(auth.id, 'REVIEW_ACCEPTED')}
                                  >
                                    Accept
                                  </Button>
                                  <Button
                                    type="button"
                                    size="sm"
                                    variant="secondary"
                                    onClick={() => submitPilotReview(auth.id, 'REVIEW_REJECTED')}
                                  >
                                    Reject
                                  </Button>
                                </div>
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
                        Pilot authorization records are non-executable control-plane governance records. No legacy account numbers, target account numbers, balances, amounts, currencies, external references, or financial payloads are projected. Review-accepted authorization records remain MIGRATION_EXECUTION_NOT_IMPLEMENTED and CUTOVER_NOT_AUTHORIZED. No operational Banking service consumes authorization results.
                      </div>
                    </div>
                  </>
                )}
              </>
            );
          })()}
          {canView && execution_preconditions && execution_preconditions.length > 0 && (() => {
            const preconditionStyle = (value: string): string => {
              switch (value) {
                case 'BANK_ACCOUNT_ONLY': return 'ready-green';
                case 'UNCHANGED': return 'ready-green';
                case 'CLEAR': return 'ready-green';
                case 'REVIEW_ACCEPTED': return 'ready-green';
                case 'ACTIVE': return 'ready-green';
                case 'VALID': return 'ready-green';
                case 'ARCHITECTURE_DEFINED': return 'ready-green';
                case 'CHANGED': return 'critical-red';
                case 'BLOCKED': return 'critical-red';
                case 'NOT_ACCEPTED': return 'inspection-blue';
                case 'INACTIVE': return 'critical-red';
                case 'INVALID': return 'critical-red';
                case 'UNAVAILABLE': return 'neutral';
                case 'NOT_IMPLEMENTED': return 'neutral';
                case 'NOT_AUTHORIZED': return 'neutral';
                default: return 'neutral';
              }
            };

            type PreconBadge = 'ready' | 'critical' | 'inspection' | 'neutral';

            const preconditionBadge = (value: string): PreconBadge => {
              switch (value) {
                case 'BANK_ACCOUNT_ONLY': return 'ready';
                case 'UNCHANGED': return 'ready';
                case 'CLEAR': return 'ready';
                case 'REVIEW_ACCEPTED': return 'ready';
                case 'ACTIVE': return 'ready';
                case 'VALID': return 'ready';
                case 'ARCHITECTURE_DEFINED': return 'ready';
                case 'CHANGED': return 'critical';
                case 'BLOCKED': return 'critical';
                case 'INACTIVE': return 'critical';
                case 'INVALID': return 'critical';
                case 'NOT_ACCEPTED': return 'inspection';
                default: return 'neutral';
              }
            };

            const conditionCount = execution_preconditions.length;
            const readyCount = execution_preconditions.filter((e) => e.summary_status === 'EXECUTION_IMPLEMENTATION_DEFERRED').length;

            return (
              <>
                <QueueList title="Future Pilot Execution Preconditions" count={conditionCount}>
                  <div className="finance-queue-body">
                    {execution_preconditions.map((precon, idx) => (
                      <WorkCard
                        key={precon.pilot_authorization_id || idx}
                        borderColor="neutral"
                        meta={
                          <StatusBadge status={preconditionBadge(precon.pilot_auth_review_state) as any}>
                            {precon.pilot_authorization_status || '—'}
                          </StatusBadge>
                        }
                        title={`Authorization ${precon.pilot_authorization_id}`}
                        detail={
                          <div style={{ fontSize: '11px', lineHeight: '1.8', color: 'var(--text-secondary)' }}>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Manifest Source Scope:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.manifest_source_scope)})`, fontWeight: 500 }}>{precon.manifest_source_scope}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Source Snapshot:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.manifest_source_snapshot)})`, fontWeight: 500 }}>{precon.manifest_source_snapshot}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Quarantine:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.exception_quarantine_state)})`, fontWeight: 500 }}>{precon.exception_quarantine_state}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Target Intake Review:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.target_intake_review_state)})`, fontWeight: 500 }}>{precon.target_intake_review_state}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Pilot Auth Review:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.pilot_auth_review_state)})`, fontWeight: 500 }}>{precon.pilot_auth_review_state}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Target Status:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.target_operational_state)})`, fontWeight: 500 }}>{precon.target_operational_state}</span>
                            </div>
                            <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                              <span>Property Boundary:</span>
                              <span style={{ color: `var(--status-${preconditionStyle(precon.property_boundary)})`, fontWeight: 500 }}>{precon.property_boundary}</span>
                            </div>
                            <div style={{ marginTop: '8px', paddingTop: '8px', borderTop: '1px solid var(--border-default)' }}>
                              <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                                <span>Lineage Contract:</span>
                                <span style={{ fontWeight: 500 }}>{precon.future_lineage_contract}</span>
                              </div>
                              <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                                <span>Execution:</span>
                                <span>{precon.future_execution_permission}</span>
                              </div>
                              <div style={{ display: 'flex', justifyContent: 'space-between', gap: '8px' }}>
                                <span>Cutover:</span>
                                <span>{precon.future_cutover_permission}</span>
                              </div>
                              <div style={{ marginTop: '4px', fontWeight: 600, textAlign: 'right', color: 'var(--text-secondary)' }}>
                                {precon.summary_status}
                              </div>
                            </div>
                          </div>
                        }
                      />
                    ))}
                  </div>
                </QueueList>

                <div style={{ marginTop: '16px', padding: '12px 16px', backgroundColor: 'var(--surface-secondary)', border: '1px solid var(--border-default)', borderRadius: '4px' }}>
                  <div style={{ fontSize: '11px', color: 'var(--text-secondary)', fontStyle: 'italic' }}>
                    Future Pilot Execution Preconditions is a read-only server projection. No source/target financial fields are read, compared, or projected. No execution, cutover, correction, or rollback action is exposed. All execution and cutover capabilities remain deferred. Every projection returns EXECUTION_IMPLEMENTATION_DEFERRED.
                  </div>
                </div>
              </>
            );
          })()}
          {canView && execution_preconditions && execution_preconditions.length === 0 && (
            <AttentionArea title="Future Pilot Execution Preconditions" badgeText="No Eligible Records" badgeType="neutral" areaType="neutral">
              <div className="finance-empty-state">
                No pilot authorization records are currently available for execution precondition evidence projection. Pilot authorization records must be non-ARCHIVED to appear here. Execution implementation remains deferred.
              </div>
            </AttentionArea>
          )}
        </MainContent>
      </SplitLayout>
    </div>
  );
}

BankingMigrationControlWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
