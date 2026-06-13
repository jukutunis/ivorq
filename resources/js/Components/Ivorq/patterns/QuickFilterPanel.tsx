import React from 'react';

export default function QuickFilterPanel({ children }: { children: React.ReactNode }) {
  return (
    <div className="quick-filter-panel">
      <div className="filter-panel-title">
        Quick Filters <a href="#" className="filter-reset">Reset</a>
      </div>
      {children}
    </div>
  );
}
