import React from 'react';
import StatusBadge, { BadgeStatus } from '../primitives/StatusBadge';

interface AttentionAreaProps {
  title: string;
  badgeText: string;
  badgeType?: BadgeStatus; // 'warning', 'critical', etc.
  areaType?: string; // e.g. 'warning' for the yellow background
  className?: string;
  style?: React.CSSProperties;
  children: React.ReactNode;
}

export default function AttentionArea({
  title,
  badgeText,
  badgeType = 'warning',
  areaType = 'warning',
  className = '',
  style,
  children,
}: AttentionAreaProps) {
  const areaClass = [areaType ? `attention-area ${areaType}` : 'attention-area', className].filter(Boolean).join(' ');

  return (
    <div className={areaClass} style={style}>
      <div className="attention-header">
        <div className="attention-title">{title}</div>
        <StatusBadge status={badgeType}>{badgeText}</StatusBadge>
      </div>
      {children}
    </div>
  );
}
