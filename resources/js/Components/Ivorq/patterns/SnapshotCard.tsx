import React, { ReactNode } from 'react';

interface SnapshotCardProps {
  value: ReactNode;
  label: ReactNode;
  statusColor?: string; // 'primary' | 'ready' | 'vip' | 'neutral' | 'critical' | 'warning' | 'inspection'
  trend?: ReactNode;
}

export default function SnapshotCard({ value, label, statusColor, trend }: SnapshotCardProps) {
  const borderStyle = statusColor ? { borderTopColor: `var(--${statusColor})` } : {};
  const valueStyle = statusColor ? { color: `var(--${statusColor})` } : {};

  return (
    <div className="snap-card" style={borderStyle}>
      {trend ? (
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div className="snap-val" style={valueStyle}>{value}</div>
          {trend}
        </div>
      ) : (
        <div className="snap-val" style={valueStyle}>{value}</div>
      )}
      <div className="snap-lbl">{label}</div>
    </div>
  );
}
