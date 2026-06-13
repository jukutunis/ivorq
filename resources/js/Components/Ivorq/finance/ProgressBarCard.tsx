import React, { ReactNode } from 'react';

interface ProgressBarCardProps {
  title: string;
  percent: number;
  barColor: string; // e.g. 'critical-red', 'ready-green', 'warning-amber'
  trend?: ReactNode; // Optional accessory next to percent
}

export default function ProgressBarCard({ title, percent, barColor, trend }: ProgressBarCardProps) {
  // Ensure the width does not exceed 100% for the visual bar fill
  const fillWidth = percent > 100 ? 100 : percent;

  return (
    <div className="attention-card" style={{ flexDirection: 'column', alignItems: 'stretch' }}>
      <div className="qi-info" style={{ width: '100%' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', marginBottom: '6px' }}>
          <span className="qi-title" style={{ fontSize: '13px' }}>{title}</span>
          <span style={{ fontSize: '13px', fontWeight: 600, color: `var(--${barColor})` }}>
            {percent}% {trend}
          </span>
        </div>
        <div style={{ height: '6px', background: 'var(--border-default)', borderRadius: '3px', overflow: 'hidden', display: 'flex' }}>
          <div style={{ width: `${fillWidth}%`, height: '100%', background: `var(--${barColor})` }}></div>
        </div>
      </div>
    </div>
  );
}
