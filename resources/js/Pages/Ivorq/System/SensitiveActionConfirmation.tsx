import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

declare const route: any;

interface Props {
  intent: string;
  intentLabel: string;
  propertyName: string;
  isConfirmed: boolean;
  confirmedAt: string | null;
  expiresAt: string | null;
}

export default function SensitiveActionConfirmation({
  intent,
  intentLabel,
  propertyName,
  isConfirmed,
  confirmedAt,
  expiresAt,
}: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  const confirmForm = useForm({
    intent,
    password: '',
  });

  const invalidateForm = useForm({
    intent,
  });

  const submitConfirm = (event: React.FormEvent) => {
    event.preventDefault();
    confirmForm.post(route('system.sensitive-action-confirmation.store'), {
      preserveScroll: true,
      onSuccess: () => confirmForm.reset('password'),
    });
  };

  const submitInvalidate = (event: React.FormEvent) => {
    event.preventDefault();
    invalidateForm.delete(route('system.sensitive-action-confirmation.destroy'), {
      preserveScroll: true,
    });
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title={`System / Sensitive Action Confirmation`}>
        <Link href="/frontdesk" preserveScroll className="btn btn-secondary">
          <Icon name="arrow-left" /> Return
        </Link>
      </WorkspaceHeader>

      <div
        style={{
          maxWidth: '600px',
          margin: '24px auto',
          background: 'var(--surface-card)',
          border: '1px solid var(--border-default)',
          borderRadius: '8px',
          padding: '24px',
        }}
      >
        <div style={{ marginBottom: '20px' }}>
          <h2
            style={{
              fontSize: '18px',
              fontWeight: 700,
              color: 'var(--text-primary)',
              margin: '0 0 4px 0',
            }}
          >
            {intentLabel}
          </h2>
          <p
            style={{
              fontSize: '13px',
              color: 'var(--text-secondary)',
              margin: 0,
            }}
          >
            Active property: {propertyName}
          </p>
        </div>

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

        {confirmForm.errors.password && (
          <div
            style={{
              border: '1px solid var(--critical-red)',
              borderRadius: '6px',
              padding: '10px 12px',
              marginBottom: '14px',
              color: 'var(--critical-red)',
              background: 'var(--surface-card)',
              fontSize: '13px',
              fontWeight: 600,
            }}
          >
            {confirmForm.errors.password}
          </div>
        )}

        {isConfirmed && (
          <div
            style={{
              border: '1px solid var(--ready-green)',
              borderRadius: '6px',
              padding: '12px',
              marginBottom: '18px',
              background: 'rgba(16, 185, 129, 0.06)',
            }}
          >
            <div style={{ fontWeight: 700, fontSize: '14px', color: 'var(--ready-green)' }}>
              Confirmation Active
            </div>
            <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
              Confirmed at: {confirmedAt}
            </div>
            {expiresAt && (
              <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                Expires at: {expiresAt}
              </div>
            )}
          </div>
        )}

        {!isConfirmed && (
          <form onSubmit={submitConfirm} style={{ display: 'grid', gap: '14px' }}>
            <p
              style={{
                fontSize: '13px',
                color: 'var(--text-secondary)',
                margin: 0,
              }}
            >
              This action requires reauthentication. Please enter your current password to confirm
              you intend to proceed with this sensitive action.
            </p>

            <div className="filter-group">
              <label className="filter-label" htmlFor="password">
                Current Password
              </label>
              <input
                id="password"
                type="password"
                className="filter-input"
                value={confirmForm.data.password}
                onChange={(event) => confirmForm.setData('password', event.target.value)}
                required
                autoFocus
                autoComplete="current-password"
              />
            </div>

            <Button type="submit" disabled={confirmForm.processing || confirmForm.data.password === ''}>
              <Icon name="save" /> Confirm
            </Button>
          </form>
        )}

        {isConfirmed && (
          <form onSubmit={submitInvalidate}>
            <Button type="submit" disabled={invalidateForm.processing}>
              <Icon name="x" /> End Confirmation
            </Button>
          </form>
        )}
      </div>
    </div>
  );
}

SensitiveActionConfirmation.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
