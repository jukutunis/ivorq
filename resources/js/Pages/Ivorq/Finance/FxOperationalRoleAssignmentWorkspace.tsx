import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import ModuleTabs from '../../../Components/Ivorq/workspace/ModuleTabs';
import SplitLayout from '../../../Components/Ivorq/workspace/SplitLayout';
import MainContent from '../../../Components/Ivorq/workspace/MainContent';
import QuickFilterPanel from '../../../Components/Ivorq/patterns/QuickFilterPanel';
import AttentionArea from '../../../Components/Ivorq/patterns/AttentionArea';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

declare const route: any;

interface ManagedUser {
  id: string;
  name: string;
  email: string;
  fx_roles: string[];
}

interface Props {
  roles: string[];
  users: ManagedUser[];
}

const financeTabs = [
  { href: '/finance/revenue-cash', label: 'Revenue & Cash' },
  { href: '/finance/accounts-payable', label: 'Accounts Payable' },
  { href: '/finance/accounts-receivable', label: 'Accounts Receivable' },
  { href: '/finance/budget-watch', label: 'Budget Watch' },
  { href: '/finance/fx-adjustments', label: 'Realized FX Adjustments' },
  { href: '/finance/fx-access-management', label: 'FX Access Management' },
];

function roleLabel(role: string): string {
  return role
    .replace(/-/g, ' ')
    .replace(/\b\w/g, (letter) => letter.toUpperCase());
}

export default function FxOperationalRoleAssignmentWorkspace({ roles, users }: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;
  const form = useForm({
    target_user_id: users[0]?.id ?? '',
    role: roles[0] ?? '',
    action: 'assign',
    reason: '',
  });

  const submit = (event: React.FormEvent) => {
    event.preventDefault();
    form.post(route('finance.fx-operational-role-assignments.store'), {
      preserveScroll: true,
      onSuccess: () => form.reset('reason'),
    });
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / FX Access Management">
        <Link href={route('finance.fx-adjustments.index')} preserveScroll className="btn btn-secondary">
          <Icon name="finance" /> FX Workspace
        </Link>
      </WorkspaceHeader>

      <ModuleTabs tabs={financeTabs} />

      <SplitLayout>
        <QuickFilterPanel>
          <form onSubmit={submit} style={{ display: 'grid', gap: '12px' }}>
            <div className="filter-group">
              <label className="filter-label">Target User</label>
              <select
                className="filter-input"
                value={form.data.target_user_id}
                onChange={(event) => form.setData('target_user_id', event.target.value)}
                required
              >
                {users.map((user) => (
                  <option key={user.id} value={user.id}>
                    {user.name} ({user.email})
                  </option>
                ))}
              </select>
              {form.errors.target_user_id && <FieldError>{form.errors.target_user_id}</FieldError>}
            </div>

            <div className="filter-group">
              <label className="filter-label">FX Operational Role</label>
              <select
                className="filter-input"
                value={form.data.role}
                onChange={(event) => form.setData('role', event.target.value)}
                required
              >
                {roles.map((role) => (
                  <option key={role} value={role}>
                    {roleLabel(role)}
                  </option>
                ))}
              </select>
              {form.errors.role && <FieldError>{form.errors.role}</FieldError>}
            </div>

            <div className="filter-group">
              <label className="filter-label">Action</label>
              <select
                className="filter-input"
                value={form.data.action}
                onChange={(event) => form.setData('action', event.target.value)}
                required
              >
                <option value="assign">Assign</option>
                <option value="revoke">Revoke</option>
              </select>
              {form.errors.action && <FieldError>{form.errors.action}</FieldError>}
            </div>

            <div className="filter-group">
              <label className="filter-label">Operational Reason</label>
              <textarea
                value={form.data.reason}
                onChange={(event) => form.setData('reason', event.target.value)}
                rows={4}
                required
                style={{
                  width: '100%',
                  resize: 'vertical',
                  border: '1px solid var(--border-default)',
                  borderRadius: '6px',
                  padding: '8px',
                  fontSize: '12px',
                }}
              />
              {form.errors.reason && <FieldError>{form.errors.reason}</FieldError>}
            </div>

            <Button type="submit" disabled={form.processing || users.length === 0}>
              <Icon name="save" /> Submit
            </Button>
          </form>
        </QuickFilterPanel>

        <MainContent>
          {(flash?.success || flash?.error) && (
            <div
              style={{
                border: `1px solid var(--${flash.success ? 'ready-green' : 'critical-red'})`,
                borderRadius: '6px',
                padding: '10px 12px',
                marginBottom: '14px',
                color: `var(--${flash.success ? 'ready-green' : 'critical-red'})`,
                background: 'var(--surface-card)',
                fontSize: '13px',
                fontWeight: 600,
              }}
            >
              {flash.success || flash.error}
            </div>
          )}

          <AttentionArea title="Current Property FX Role Assignments" badgeText={`${users.length} Users`} badgeType="inspection" areaType="inspection">
            <div style={{ display: 'grid', gap: '10px' }}>
              {users.length === 0 && (
                <div style={{ color: 'var(--text-secondary)', fontSize: '13px' }}>
                  No active users are available in the current property context.
                </div>
              )}

              {users.map((user) => (
                <div
                  key={user.id}
                  style={{
                    display: 'grid',
                    gridTemplateColumns: 'minmax(180px, 1fr) minmax(160px, .8fr)',
                    gap: '12px',
                    borderTop: '1px solid var(--border-default)',
                    paddingTop: '10px',
                    fontSize: '13px',
                  }}
                >
                  <div>
                    <div style={{ fontWeight: 700 }}>{user.name}</div>
                    <div style={{ color: 'var(--text-secondary)', fontSize: '12px' }}>{user.email}</div>
                  </div>
                  <div style={{ fontWeight: 700 }}>
                    {user.fx_roles.length > 0 ? user.fx_roles.map(roleLabel).join(', ') : 'No FX operational role'}
                  </div>
                </div>
              ))}
            </div>
          </AttentionArea>
        </MainContent>
      </SplitLayout>
    </div>
  );
}

function FieldError({ children }: { children: React.ReactNode }) {
  return <div style={{ color: 'var(--critical-red)', fontSize: '11px', marginTop: '4px' }}>{children}</div>;
}

FxOperationalRoleAssignmentWorkspace.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
