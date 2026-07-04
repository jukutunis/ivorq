import React from 'react';
import { Link, useForm, usePage } from '@inertiajs/react';
import '../../../../css/ivorq-prototype.css';

import IvorqLayout from '../../../Layouts/IvorqLayout';
import WorkspaceHeader from '../../../Components/Ivorq/workspace/WorkspaceHeader';
import Button from '../../../Components/Ivorq/primitives/Button';
import Icon from '../../../Components/Ivorq/primitives/Icon';

declare const route: any;

interface Props {
  isBroadAdmin: boolean;
  isActive: boolean;
  activatedAt: string | null;
  expiresAt: string | null;
  reason: string | null;
  propertyName: string;
}

export default function FxBreakGlassAccess({
  isBroadAdmin,
  isActive,
  activatedAt,
  expiresAt,
  reason,
  propertyName,
}: Props) {
  const { flash } = usePage<{ flash?: { success?: string | null; error?: string | null } }>().props;

  const activateForm = useForm({
    reason: '',
  });

  const deactivateForm = useForm({});

  const submitActivate = (event: React.FormEvent) => {
    event.preventDefault();
    activateForm.post(route('finance.fx-break-glass.store'), {
      preserveScroll: true,
      onSuccess: () => activateForm.reset('reason'),
    });
  };

  const submitDeactivate = (event: React.FormEvent) => {
    event.preventDefault();
    deactivateForm.delete(route('finance.fx-break-glass.destroy'), {
      preserveScroll: true,
    });
  };

  return (
    <div className="workspace">
      <WorkspaceHeader title="Finance / FX Break‑Glass Access">
        <Link href={route('finance.fx-adjustments.index')} preserveScroll className="btn btn-secondary">
          <Icon name="finance" /> FX Workspace
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
            FX Break‑Glass Access
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

        {!isBroadAdmin && (
          <div
            style={{
              border: '1px solid var(--border-default)',
              borderRadius: '6px',
              padding: '12px',
              marginBottom: '18px',
              fontSize: '13px',
              color: 'var(--text-secondary)',
            }}
          >
            Break‑glass access is only required for broad administrators. You do not need break‑glass
            activation to use the FX workspace.
          </div>
        )}

        {isBroadAdmin && !isActive && (
          <div>
            <div
              style={{
                border: '1px solid var(--critical-red)',
                borderRadius: '6px',
                padding: '10px 12px',
                marginBottom: '18px',
                color: 'var(--critical-red)',
                background: 'rgba(239, 68, 68, 0.06)',
                fontSize: '13px',
                fontWeight: 600,
              }}
            >
              FX break‑glass access is not active. You must activate temporary FX operational access
              before using the FX workspace or initiating any FX lifecycle action.
            </div>

            <div style={{ marginBottom: '18px' }}>
              <p
                style={{
                  fontSize: '13px',
                  color: 'var(--text-secondary)',
                  margin: '0 0 12px 0',
                }}
              >
                Activation requires a valid sensitive action confirmation for FX break‑glass and an
                operational reason. This activation is temporary, auditable, and does not grant any
                additional permissions or roles.
              </p>
              <Link
                href={route('system.sensitive-action-confirmation.index', { intent: 'fx-break-glass' })}
                className="btn btn-secondary"
                style={{ fontSize: '12px' }}
              >
                <Icon name="shield" /> Confirm Identity
              </Link>
            </div>

            <form onSubmit={submitActivate} style={{ display: 'grid', gap: '14px' }}>
              <div className="filter-group">
                <label className="filter-label" htmlFor="reason">
                  Operational Reason
                </label>
                <textarea
                  id="reason"
                  value={activateForm.data.reason}
                  onChange={(event) => activateForm.setData('reason', event.target.value)}
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
                {activateForm.errors.reason && (
                  <div style={{ color: 'var(--critical-red)', fontSize: '11px', marginTop: '4px' }}>
                    {activateForm.errors.reason}
                  </div>
                )}
              </div>

              <Button type="submit" disabled={activateForm.processing || activateForm.data.reason.trim() === ''}>
                <Icon name="save" /> Activate Break‑Glass
              </Button>
            </form>
          </div>
        )}

        {isBroadAdmin && isActive && (
          <div>
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
                Break‑Glass Active
              </div>
              <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                Activated at: {activatedAt}
              </div>
              {expiresAt && (
                <div style={{ fontSize: '12px', color: 'var(--text-secondary)' }}>
                  Expires at: {expiresAt}
                </div>
              )}
              {reason && (
                <div style={{ fontSize: '12px', color: 'var(--text-secondary)', marginTop: '4px' }}>
                  Reason: {reason}
                </div>
              )}
            </div>

            <form onSubmit={submitDeactivate}>
              <Button type="submit" disabled={deactivateForm.processing}>
                <Icon name="x" /> Deactivate Break‑Glass
              </Button>
            </form>
          </div>
        )}
      </div>
    </div>
  );
}

FxBreakGlassAccess.layout = (page: React.ReactNode) => <IvorqLayout children={page} />;
