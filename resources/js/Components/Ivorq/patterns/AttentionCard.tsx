import React from 'react';

interface AttentionCardProps {
  title: React.ReactNode;
  meta: React.ReactNode;
  actions: React.ReactNode;
}

export default function AttentionCard({ title, meta, actions }: AttentionCardProps) {
  return (
    <div className="attention-card">
      <div className="qi-info">
        <span className="qi-title" style={{ fontSize: '13px' }}>
          {title}
        </span>
        <span className="qi-meta">{meta}</span>
      </div>
      <div style={{ display: 'flex', gap: '6px' }}>{actions}</div>
    </div>
  );
}
