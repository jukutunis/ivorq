import React from 'react';

interface QueueItemProps {
  title: React.ReactNode;
  meta: React.ReactNode;
  actions?: React.ReactNode;
}

export default function QueueItem({ title, meta, actions }: QueueItemProps) {
  return (
    <div className="queue-item">
      <div className="qi-info">
        <div className="qi-title">{title}</div>
        <div className="qi-meta">{meta}</div>
      </div>
      {actions && <div className="qi-right">{actions}</div>}
    </div>
  );
}
