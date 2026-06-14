import React, { useEffect, useState, useRef } from 'react';
import axios from 'axios';
import { Link } from '@inertiajs/react';
import Icon from '../primitives/Icon';
import NotificationCard from '../notifications/NotificationCard';

export default function NotificationBell() {
  const [unreadCount, setUnreadCount] = useState(0);
  const [notifications, setNotifications] = useState([]);
  const [isOpen, setIsOpen] = useState(false);
  const dropdownRef = useRef<HTMLDivElement>(null);

  const fetchUnreadCount = async () => {
    try {
      const res = await axios.get('/api/notifications/unread-count');
      setUnreadCount(res.data.count);
    } catch (e) {
      console.error(e);
    }
  };

  const fetchNotifications = async () => {
    try {
      const res = await axios.get('/api/notifications?per_page=5');
      setNotifications(res.data.data || []);
    } catch (e) {
      console.error(e);
    }
  };

  const markAllRead = async (e: React.MouseEvent) => {
    e.preventDefault();
    try {
      await axios.post('/api/notifications/mark-all-read');
      setUnreadCount(0);
      setNotifications(notifications.map((n: any) => ({ ...n, is_read: true })));
    } catch (e) {
      console.error(e);
    }
  };

  const markRead = async (id: string) => {
    try {
      await axios.put(`/api/notifications/${id}/read`);
      setUnreadCount(prev => Math.max(0, prev - 1));
      setNotifications(notifications.map((n: any) => n.id === id ? { ...n, is_read: true } : n));
    } catch (e) {
      console.error(e);
    }
  };

  useEffect(() => {
    fetchUnreadCount();
    
    // Polling every 30 seconds
    const interval = setInterval(fetchUnreadCount, 30000);
    return () => clearInterval(interval);
  }, []);

  useEffect(() => {
    if (isOpen) {
      fetchNotifications();
    }
  }, [isOpen]);

  useEffect(() => {
    const handleClickOutside = (event: MouseEvent) => {
      if (dropdownRef.current && !dropdownRef.current.contains(event.target as Node)) {
        setIsOpen(false);
      }
    };
    document.addEventListener('mousedown', handleClickOutside);
    return () => document.removeEventListener('mousedown', handleClickOutside);
  }, []);

  return (
    <div className="notification-bell" ref={dropdownRef} style={{ position: 'relative' }}>
      <button 
        onClick={() => setIsOpen(!isOpen)}
        style={{ background: 'none', border: 'none', cursor: 'pointer', position: 'relative', display: 'flex' }}
      >
        <Icon name="bell" style={{ width: '18px', height: '18px' }} />
        {unreadCount > 0 && (
          <div className="notification-dot" style={{
            position: 'absolute',
            top: '-4px',
            right: '-4px',
            background: 'var(--critical-red, #ef4444)',
            color: 'white',
            borderRadius: '10px',
            fontSize: '10px',
            padding: '2px 4px',
            fontWeight: 'bold',
            lineHeight: 1
          }}>
            {unreadCount > 99 ? '99+' : unreadCount}
          </div>
        )}
      </button>

      {isOpen && (
        <div className="notification-dropdown" style={{
            position: 'absolute',
            top: '40px',
            right: '0',
            width: '350px',
            background: 'white',
            border: '1px solid #e2e8f0',
            borderRadius: '8px',
            boxShadow: '0 10px 15px -3px rgba(0,0,0,0.1)',
            zIndex: 50,
            overflow: 'hidden'
        }}>
          <div className="notif-header" style={{
              display: 'flex', justifyContent: 'space-between', alignItems: 'center',
              padding: '12px 16px', borderBottom: '1px solid #e2e8f0', background: '#f8fafc'
          }}>
            <div style={{ fontWeight: 600, color: '#1e293b' }}>Notifications</div>
            <button
              onClick={markAllRead}
              style={{
                fontSize: '12px',
                color: 'var(--primary-500, #3b82f6)',
                background: 'none',
                border: 'none',
                cursor: 'pointer',
                fontWeight: 500,
              }}
            >
              Mark all read
            </button>
          </div>
          
          <div className="notif-body" style={{ maxHeight: '350px', overflowY: 'auto' }}>
            {notifications.length === 0 ? (
                <div style={{ padding: '24px', textAlign: 'center', color: '#64748b' }}>
                    No recent notifications
                </div>
            ) : (
                notifications.map((notif: any) => (
                    <NotificationCard key={notif.id} notification={notif} onMarkRead={markRead} />
                ))
            )}
          </div>
          
          <div style={{ padding: '8px', textAlign: 'center', borderTop: '1px solid #e2e8f0', background: '#f8fafc' }}>
            <Link href="/notifications" onClick={() => setIsOpen(false)} style={{
                fontSize: '13px', color: 'var(--primary-600, #2563eb)', textDecoration: 'none', fontWeight: 500
            }}>
                View All Notifications
            </Link>
          </div>
        </div>
      )}
    </div>
  );
}
