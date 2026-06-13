import React from 'react';

interface SnapshotCardProps {
  value: string | number;
  label: string;
  statusColor?: string; // 'primary' | 'ready' | 'vip' | 'neutral' | 'critical' | 'warning' | 'inspection'
}

export default function SnapshotCard({ value, label, statusColor }: SnapshotCardProps) {
  const borderStyle = statusColor ? { borderTopColor: `var(--${statusColor})` } : {};
  const valueStyle = statusColor ? { color: `var(--${statusColor})` } : {};

  return (
    <div className="snap-card" style={borderStyle}>
      <div className="snap-val" style={valueStyle}>
        {value}
      </div>
      <div className="snap-lbl">{label}</div>
    </div>
  );
}
