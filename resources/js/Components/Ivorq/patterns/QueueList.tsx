import React from 'react';

interface QueueListProps {
  title: React.ReactNode;
  count?: number;
  className?: string;
  style?: React.CSSProperties;
  headerStyle?: React.CSSProperties;
  actions?: React.ReactNode;
  children: React.ReactNode;
}

export default function QueueList({ title, count, className = '', style, headerStyle, actions, children }: QueueListProps) {
  return (
    <div className={`queue-list ${className}`.trim()} style={style}>
      <div className="queue-header" style={headerStyle}>
        <div style={{ display: 'flex', alignItems: 'center', gap: '8px' }}>{title}</div>
        {count !== undefined && <div className="col-count">{count}</div>}
        {actions && actions}
      </div>
      {children}
    </div>
  );
}
