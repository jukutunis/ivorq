import React from 'react';
import { Link, usePage } from '@inertiajs/react';

export interface TabItem {
  id?: string;
  href?: string;
  label: string;
  badge?: number;
}

interface ModuleTabsProps {
  tabs: TabItem[];
  activeTabId?: string;
  onTabChange?: (id: string) => void;
}

export default function ModuleTabs({ tabs, activeTabId, onTabChange }: ModuleTabsProps) {
  const { url } = usePage();

  return (
    <div className="module-tabs">
      {tabs.map((tab) => {
        const isActive = tab.href ? (url === tab.href || url.startsWith(`${tab.href}?`)) : activeTabId === tab.id;

        const content = (
          <>
            {tab.label}
            {tab.badge !== undefined && (
              <span className="tab-badge">{tab.badge}</span>
            )}
          </>
        );

        if (tab.href) {
          return (
            <Link
              key={tab.href}
              href={tab.href}
              preserveState
              className={`tab ${isActive ? 'active' : ''}`}
            >
              {content}
            </Link>
          );
        }

        return (
          <div
            key={tab.id || tab.label}
            className={`tab ${isActive ? 'active' : ''}`}
            onClick={() => onTabChange && tab.id && onTabChange(tab.id)}
          >
            {content}
          </div>
        );
      })}
    </div>
  );
}
