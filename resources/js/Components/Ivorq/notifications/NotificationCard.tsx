import React from 'react';
import { Link } from '@inertiajs/react';
import Icon from '../primitives/Icon';

interface NotificationCardProps {
  notification: {
    id: string;
    type: string;
    title: string;
    body: string;
    priority: string;
    is_read: boolean;
    created_at: string;
    data: any;
  };
  onMarkRead: (id: string) => void;
}

export default function NotificationCard({ notification, onMarkRead }: NotificationCardProps) {
  const getPriorityColor = (priority: string) => {
    switch (priority) {
      case 'critical': return 'var(--critical-red, #ef4444)';
      case 'high': return 'var(--warning-amber, #f59e0b)';
      default: return 'var(--primary-500, #3b82f6)';
    }
  };

  const getModuleIcon = (type: string) => {
    if (type.includes('Task')) return 'check-circle';
    if (type.includes('WorkOrder')) return 'wrench';
    return 'bell';
  };

  const getDeepLink = () => {
    // Basic logic based on type or data payload
    if (notification.type.includes('Task')) {
      // In a real app, you might route to a generic task view or module specific view
      return `/housekeeping/room-board`; // dummy fallback
    }
    return '#';
  };

  return (
    <div className={`notif-card ${!notification.is_read ? 'unread' : ''}`} style={{ 
        padding: '12px', 
        borderBottom: '1px solid #e2e8f0', 
        display: 'flex', 
        gap: '12px',
        backgroundColor: notification.is_read ? 'transparent' : '#f0fdf4'
    }}>
      <div style={{ color: getPriorityColor(notification.priority), paddingTop: '4px' }}>
        <Icon name={getModuleIcon(notification.type) as any} style={{ width: '20px', height: '20px' }} />
      </div>
      
      <div style={{ flex: 1 }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'flex-start' }}>
          <div style={{ fontWeight: 600, fontSize: '14px', color: '#1e293b' }}>
            <Link href={getDeepLink()} style={{ textDecoration: 'none', color: 'inherit' }}>
                {notification.title}
            </Link>
          </div>
          {!notification.is_read && (
            <button 
              onClick={() => onMarkRead(notification.id)}
              style={{ background: 'none', border: 'none', cursor: 'pointer', color: '#64748b' }}
              title="Mark as read"
            >
              <Icon name="check" style={{ width: '16px', height: '16px' }} />
            </button>
          )}
        </div>
        
        <div style={{ fontSize: '13px', color: '#475569', marginTop: '4px', marginBottom: '4px' }}>
          {notification.body}
        </div>
        
        <div style={{ fontSize: '11px', color: '#94a3b8', display: 'flex', gap: '8px', alignItems: 'center' }}>
          <span>{new Date(notification.created_at).toLocaleString()}</span>
          {!notification.is_read && (
            <span style={{ width: '6px', height: '6px', borderRadius: '50%', backgroundColor: '#3b82f6' }}></span>
          )}
        </div>
      </div>
    </div>
  );
}
