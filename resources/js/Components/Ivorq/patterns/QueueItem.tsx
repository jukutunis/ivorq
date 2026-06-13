import React from 'react';

interface QueueItemProps {
  title: React.ReactNode;
  meta: React.ReactNode;
  actions?: React.ReactNode;
  style?: React.CSSProperties;
  avatar?: React.ReactNode;
}

export default function QueueItem({ title, meta, actions, style, avatar }: QueueItemProps) {
  return (
    <div className="queue-item" style={style}>
      {avatar ? (
        <div style={{ display: 'flex', alignItems: 'center', gap: '12px' }}>
          {avatar}
          <div className="qi-info">
            <div className="qi-title" style={{ marginBottom: 0 }}>{title}</div>
            <div className="qi-meta">{meta}</div>
          </div>
        </div>
      ) : (
        <div className="qi-info">
          <div className="qi-title">{title}</div>
          <div className="qi-meta">{meta}</div>
        </div>
      )}
      {actions && <div className="qi-right">{actions}</div>}
    </div>
  );
}
