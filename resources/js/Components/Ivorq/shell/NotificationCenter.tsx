import React from 'react';
import Icon from '../primitives/Icon';

export default function NotificationCenter() {
  return (
    <div className="notification-bell">
      <Icon name="bell" style={{ width: '18px', height: '18px' }} />
      <div className="notification-dot"></div>

      <div className="notification-dropdown">
        <div className="notif-header">
          <div>Notifications</div>
          <a
            href="#"
            style={{
              fontSize: '12px',
              color: 'var(--primary-500)',
              textDecoration: 'none',
              fontWeight: 500,
            }}
          >
            Mark all read
          </a>
        </div>
        <div className="notif-body">
          <div className="notif-item">
            <div className="notif-title" style={{ color: 'var(--vip-gold)' }}>
              VIP Arrival Needs Room
            </div>
            <div className="notif-meta">Front Desk • Smith, James (ETA 14:00)</div>
          </div>
          <div className="notif-item">
            <div className="notif-title" style={{ color: 'var(--critical-red)' }}>
              PM Overdue: Chiller B
            </div>
            <div className="notif-meta">Engineering • Overdue by 4 hours</div>
          </div>
          <div className="notif-item">
            <div className="notif-title" style={{ color: 'var(--warning-amber)' }}>
              Low Stock Alert
            </div>
            <div className="notif-meta">Inventory • Bath Towels below PAR in Main Store</div>
          </div>
          <div className="notif-item">
            <div className="notif-title">Leave Request Pending</div>
            <div className="notif-meta">HRIS • Ni Luh Sari (Housekeeping)</div>
          </div>
        </div>
      </div>
    </div>
  );
}
