import React, { ReactNode } from 'react';

interface WorkCardProps {
  borderColor?: string; // CSS color variable name e.g., 'warning-amber'
  className?: string;
  meta: ReactNode;
  title: ReactNode;
  detail?: ReactNode;
  actions?: ReactNode;
}

export default function WorkCard({ borderColor, className = '', meta, title, detail, actions }: WorkCardProps) {
  const borderStyle = borderColor ? { borderLeftColor: `var(--${borderColor})` } : {};

  return (
    <div className={['work-card', className].filter(Boolean).join(' ')} style={borderStyle}>
      <div className="wc-meta">{meta}</div>
      <div className="wc-title">{title}</div>
      {detail && <div className="wc-detail">{detail}</div>}
      {actions && <div className="wc-actions">{actions}</div>}
    </div>
  );
}
