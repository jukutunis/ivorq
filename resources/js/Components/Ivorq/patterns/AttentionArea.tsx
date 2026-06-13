import React from 'react';
import StatusBadge, { BadgeStatus } from '../primitives/StatusBadge';

interface AttentionAreaProps {
  title: string;
  badgeText: string;
  badgeType?: BadgeStatus; // 'warning', 'critical', etc.
  areaType?: string; // e.g. 'warning' for the yellow background
  children: React.ReactNode;
}

export default function AttentionArea({ title, badgeText, badgeType = 'warning', areaType = 'warning', children }: AttentionAreaProps) {
  const areaClass = areaType ? `attention-area ${areaType}` : 'attention-area';

  return (
    <div className={areaClass}>
      <div className="attention-header">
        <div className="attention-title">{title}</div>
        <StatusBadge status={badgeType}>{badgeText}</StatusBadge>
      </div>
      {children}
    </div>
  );
}
