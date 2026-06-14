import React from 'react';
import NotificationCard from './NotificationCard';

interface NotificationListProps {
  notifications: any[];
  onMarkRead: (id: string) => void;
}

export default function NotificationList({ notifications, onMarkRead }: NotificationListProps) {
  if (notifications.length === 0) {
    return (
      <div style={{ padding: '32px', textAlign: 'center', color: '#64748b' }}>
        No notifications to display.
      </div>
    );
  }

  return (
    <div className="notification-list" style={{ display: 'flex', flexDirection: 'column' }}>
      {notifications.map(notif => (
        <NotificationCard 
          key={notif.id} 
          notification={notif} 
          onMarkRead={onMarkRead} 
        />
      ))}
    </div>
  );
}
