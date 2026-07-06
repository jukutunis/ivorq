import React, { ReactNode } from 'react';

export type BadgeStatus =
  | 'vip'
  | 'critical'
  | 'warning'
  | 'ready'
  | 'inspection'
  | 'pending'
  | 'neutral'
  | 'draft'
  | 'overdue'
  | 'vacant'
  | 'success'
  | 'error'
  | 'info';

interface StatusBadgeProps {
  status: BadgeStatus;
  className?: string;
  style?: React.CSSProperties;
  children: ReactNode;
}

export default function StatusBadge({ status, className = '', style, children }: StatusBadgeProps) {
  const combinedClass = ['badge', `b-${status}`, className].filter(Boolean).join(' ');
  return (
    <span className={combinedClass} style={style}>
      {children}
    </span>
  );
}
