import { useState, useEffect, useCallback, useRef } from 'react';
import { apiRequest } from '@/lib/api/config';
import { toast } from 'sonner';

// ══════════════════════════════════════════════════════════════
// Notification Types & Backend-Powered Hook
// ══════════════════════════════════════════════════════════════

export interface Notification {
  id: string;
  title: string;
  message: string;
  type: 'leave' | 'expense' | 'task' | 'helpdesk' | 'general' | 'announcement';
  timestamp: string;
  read: boolean;
}

// Unwrap PHP envelope
function unwrap<T>(result: { data: T | null; error: string | null }): { data: T | null; error: string | null } {
  if (result.error) return result;
  const d = result.data as Record<string, unknown> | null;
  if (d && typeof d === 'object' && 'success' in d) {
    if (d.success === false) return { data: null, error: (d.message as string) || (d.error as string) || 'Request failed' };
    if ('data' in d && d.data != null) return { data: d.data as T, error: null };
    if (d.success === true) return { data: null, error: null };
  }
  return result;
}

export function useNotifications(employeeId: number) {
  const [notifications, setNotifications] = useState<Notification[]>([]);
  const [unreadCount, setUnreadCount] = useState(0);
  const [loading, setLoading] = useState(false);
  const fetchTimerRef = useRef<ReturnType<typeof setTimeout> | null>(null);

  // Fetch from backend
  const fetchNotifications = useCallback(async () => {
    if (!employeeId) return;
    const { data, error } = await unwrap<{
      items: Array<{ id: number; title: string; message: string; type: string; is_read: boolean; created_at: string }>;
      unread_count: number;
    }>(apiRequest(`/ess/notifications?employee_id=${employeeId}&limit=50`));

    if (!error && data) {
      const notifs: Notification[] = (data.items || []).map(item => ({
        id: String(item.id),
        title: item.title || '',
        message: item.message || '',
        type: (item.type || 'general') as Notification['type'],
        timestamp: item.created_at || '',
        read: !!item.is_read,
      }));
      setNotifications(notifs);
      setUnreadCount(data.unread_count || 0);
    }
  }, [employeeId]);

  // Fetch unread count only (lightweight)
  const fetchUnreadCount = useCallback(async () => {
    if (!employeeId) return;
    const { data, error } = await unwrap<{ unread_count: number }>(
      apiRequest(`/ess/notifications?employee_id=${employeeId}&limit=1`)
    );
    if (!error && data) {
      setUnreadCount(data.unread_count || 0);
    }
  }, [employeeId]);

  // Initial load + polling every 30s
  useEffect(() => {
    if (!employeeId) return;
    fetchNotifications();
    const interval = setInterval(fetchUnreadCount, 30000);
    return () => clearInterval(interval);
  }, [employeeId, fetchNotifications, fetchUnreadCount]);

  // Local add (for in-app events like leave/expense/task)
  const addNotification = useCallback(
    (title: string, message: string, type: Notification['type'] = 'general') => {
      const newNotification: Notification = {
        id: `local-${Date.now()}-${Math.random().toString(36).slice(2, 9)}`,
        title,
        message,
        type,
        timestamp: new Date().toISOString(),
        read: false,
      };
      setNotifications(prev => [newNotification, ...prev]);
      setUnreadCount(prev => prev + 1);
    },
    []
  );

  // Mark single as read via backend
  const markAsRead = useCallback(
    async (id: string) => {
      // Optimistic update
      setNotifications(prev => prev.map(n => (n.id === id ? { ...n, read: true } : n)));
      setUnreadCount(prev => Math.max(0, prev - 1));

      // Only call backend for non-local notifications
      if (!id.startsWith('local-')) {
        unwrap(apiRequest('/ess/notifications', {
          method: 'PUT',
          body: JSON.stringify({ id: parseInt(id), employee_id: String(employeeId) }),
        }));
      }
    },
    [employeeId]
  );

  // Mark all as read via backend
  const markAllRead = useCallback(async () => {
    setNotifications(prev => prev.map(n => ({ ...n, read: true })));
    setUnreadCount(0);
    unwrap(apiRequest('/ess/notifications', {
      method: 'PUT',
      body: JSON.stringify({ mark_all: true, employee_id: String(employeeId) }),
    }));
  }, [employeeId]);

  // Clear all (local only — hides them, doesn't delete from backend)
  const clearAll = useCallback(() => {
    setNotifications([]);
    setUnreadCount(0);
  }, []);

  return {
    notifications,
    unreadCount,
    loading,
    fetchNotifications,
    addNotification,
    markAsRead,
    markAllRead,
    clearAll,
  };
}