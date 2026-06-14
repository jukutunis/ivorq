import React, { useState, useEffect } from 'react';
import IvorqLayout from '@/Layouts/IvorqLayout';
import { Head } from '@inertiajs/react';
import axios from 'axios';
import NotificationList from '@/Components/Ivorq/notifications/NotificationList';
import Icon from '@/Components/Ivorq/primitives/Icon';

export default function NotificationIndex() {
  const [notifications, setNotifications] = useState([]);
  const [loading, setLoading] = useState(true);
  const [page, setPage] = useState(1);
  const [hasMore, setHasMore] = useState(false);

  const fetchNotifications = async (pageNum = 1) => {
    try {
      setLoading(true);
      const res = await axios.get(`/api/notifications?page=${pageNum}&per_page=20`);
      if (pageNum === 1) {
        setNotifications(res.data.data);
      } else {
        setNotifications(prev => [...prev, ...res.data.data]);
      }
      setHasMore(res.data.next_page_url !== null);
      setPage(pageNum);
    } catch (e) {
      console.error(e);
    } finally {
      setLoading(false);
    }
  };

  useEffect(() => {
    fetchNotifications();
  }, []);

  const markAllRead = async () => {
    try {
      await axios.post('/api/notifications/mark-all-read');
      setNotifications(notifications.map((n: any) => ({ ...n, is_read: true })));
    } catch (e) {
      console.error(e);
    }
  };

  const markRead = async (id: string) => {
    try {
      await axios.put(`/api/notifications/${id}/read`);
      setNotifications(notifications.map((n: any) => n.id === id ? { ...n, is_read: true } : n));
    } catch (e) {
      console.error(e);
    }
  };

  return (
    <IvorqLayout>
      <Head title="Notification Center" />
      
      <div style={{ padding: '24px', maxWidth: '800px', margin: '0 auto' }}>
        <div style={{ display: 'flex', justifyContent: 'space-between', alignItems: 'center', marginBottom: '24px' }}>
          <h1 style={{ fontSize: '24px', fontWeight: 600, color: '#0f172a', display: 'flex', alignItems: 'center', gap: '8px' }}>
            <Icon name="bell" />
            Notification Center
          </h1>
          <button
            onClick={markAllRead}
            style={{
              padding: '8px 16px',
              background: 'white',
              border: '1px solid #cbd5e1',
              borderRadius: '6px',
              cursor: 'pointer',
              fontWeight: 500,
              color: '#334155'
            }}
          >
            Mark All as Read
          </button>
        </div>

        <div style={{ background: 'white', borderRadius: '8px', border: '1px solid #e2e8f0', overflow: 'hidden' }}>
          {loading && page === 1 ? (
            <div style={{ padding: '32px', textAlign: 'center', color: '#64748b' }}>Loading...</div>
          ) : (
            <NotificationList notifications={notifications} onMarkRead={markRead} />
          )}

          {hasMore && (
            <div style={{ padding: '16px', textAlign: 'center', borderTop: '1px solid #e2e8f0' }}>
              <button
                onClick={() => fetchNotifications(page + 1)}
                disabled={loading}
                style={{
                  padding: '8px 16px',
                  background: 'none',
                  border: '1px solid #cbd5e1',
                  borderRadius: '6px',
                  cursor: loading ? 'not-allowed' : 'pointer',
                  color: '#334155'
                }}
              >
                {loading ? 'Loading...' : 'Load More'}
              </button>
            </div>
          )}
        </div>
      </div>
    </IvorqLayout>
  );
}
