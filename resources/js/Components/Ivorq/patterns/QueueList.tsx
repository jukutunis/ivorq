import React from 'react';

interface QueueListProps {
  title: React.ReactNode;
  count?: number;
  children: React.ReactNode;
}

export default function QueueList({ title, count, children }: QueueListProps) {
  return (
    <div className="queue-list">
      <div className="queue-header">
        <div>{title}</div>
        {count !== undefined && <div className="col-count">{count}</div>}
      </div>
      {children}
    </div>
  );
}
