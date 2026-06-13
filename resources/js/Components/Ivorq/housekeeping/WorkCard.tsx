import React, { ReactNode } from 'react';

interface WorkCardProps {
  borderColor?: string; // CSS color variable name e.g., 'warning-amber'
  meta: ReactNode;
  title: ReactNode;
  detail?: ReactNode;
  actions?: ReactNode;
}

export default function WorkCard({ borderColor, meta, title, detail, actions }: WorkCardProps) {
  const borderStyle = borderColor ? { borderLeftColor: `var(--${borderColor})` } : {};

  return (
    <div className="work-card" style={borderStyle}>
      <div className="wc-meta">{meta}</div>
      <div className="wc-title">{title}</div>
      {detail && <div className="wc-detail">{detail}</div>}
      {actions && <div className="wc-actions">{actions}</div>}
    </div>
  );
}
