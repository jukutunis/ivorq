import React from 'react';

export interface TabItem {
  id: string;
  label: string;
  badge?: number;
}

interface ModuleTabsProps {
  tabs: TabItem[];
  activeTabId: string;
  onTabChange?: (id: string) => void;
}

export default function ModuleTabs({ tabs, activeTabId, onTabChange }: ModuleTabsProps) {
  return (
    <div className="module-tabs">
      {tabs.map((tab) => (
        <div
          key={tab.id}
          className={`tab ${activeTabId === tab.id ? 'active' : ''}`}
          onClick={() => onTabChange && onTabChange(tab.id)}
        >
          {tab.label}
          {tab.badge !== undefined && (
            <span className="tab-badge">{tab.badge}</span>
          )}
        </div>
      ))}
    </div>
  );
}
