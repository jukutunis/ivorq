import React from 'react';
import Icon from '../primitives/Icon';

export default function TopNav() {
  return (
    <nav className="topbar-nav">
      <div className="nav-item">
        <Icon name="home" style={{ color: '#94A3B8', marginRight: '6px' }} />
        Home
      </div>
      <div className="nav-item active">
        <Icon name="frontdesk" style={{ color: '#3B82F6', marginRight: '6px' }} />
        Front Desk
      </div>
      <div className="nav-item">
        <Icon name="housekeeping" style={{ color: '#10B981', marginRight: '6px' }} />
        Housekeeping
      </div>
      <div className="nav-item">
        <Icon name="engineering" style={{ color: '#F97316', marginRight: '6px' }} />
        Engineering
      </div>
      <div className="nav-item">
        <Icon name="inventory" style={{ color: '#6366F1', marginRight: '6px' }} />
        Inventory
      </div>
      <div className="nav-item">
        <Icon name="procurement" style={{ color: '#F59E0B', marginRight: '6px' }} />
        Procurement
      </div>
      <div className="nav-item">
        <Icon name="hris" style={{ color: '#8B5CF6', marginRight: '6px' }} />
        HRIS
      </div>
      <div className="nav-item">
        <Icon name="finance" style={{ color: '#22C55E', marginRight: '6px' }} />
        Finance
      </div>
      <div className="nav-item">
        <Icon name="reports" style={{ color: '#06B6D4', marginRight: '6px' }} />
        Reports
      </div>
      <div className="nav-item">
        <Icon name="ai" style={{ color: '#A855F7', marginRight: '6px' }} />
        AI Assistant
      </div>
    </nav>
  );
}
