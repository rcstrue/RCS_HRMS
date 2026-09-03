'use client';

import { useEffect, useCallback, useRef } from 'react';
import { apiRequest } from '@/lib/api/config';
import { logger } from '@/lib/logger';

// ══════════════════════════════════════════════════════════════
// usePushNotifications — Registers service worker, subscribes to
// Web Push, and sends the subscription to the HRMS backend.
// ══════════════════════════════════════════════════════════════

const SUBSCRIBED_KEY = 'ess_push_subscribed_v2';

/**
 * Convert a base64 VAPID key (URL-safe) to Uint8Array for the browser API.
 */
function urlBase64ToUint8Array(base64String: string): Uint8Array {
  const padding = '='.repeat((4 - (base64String.length % 4)) % 4);
  const base64 = (base64String + padding).replace(/-/g, '+').replace(/_/g, '/');
  const rawData = window.atob(base64);
  const outputArray = new Uint8Array(rawData.length);
  for (let i = 0; i < rawData.length; ++i) {
    outputArray[i] = rawData.charCodeAt(i);
  }
  return outputArray;
}

export interface PushState {
  supported: boolean;       // Browser supports push
  permission: NotificationPermission;
  subscribed: boolean;      // Currently subscribed
  loading: boolean;         // Subscription in progress
}

export function usePushNotifications(employeeId: number | undefined) {
  const stateRef = useRef<PushState>({
    supported: false,
    permission: 'default',
    subscribed: false,
    loading: false,
  });
  const initDoneRef = useRef(false);

  /**
   * Register the service worker (idempotent).
   */
  const registerSW = useCallback(async (): Promise<ServiceWorkerRegistration | null> => {
    if (!('serviceWorker' in navigator)) return null;
    try {
      // Check if there's already a registration at scope '/'
      const existing = await navigator.serviceWorker.getRegistration('/');
      if (existing) return existing;

      const reg = await navigator.serviceWorker.register('/sw.js', { scope: '/' });
      return reg;
    } catch (err) {
      logger.error('SW registration failed:', err);
      return null;
    }
  }, []);

  /**
   * Fetch VAPID public key from the API.
   * NOTE: PHP returns { success, data: { vapid_public_key } } — must unwrap.
   */
  const fetchVapidKey = useCallback(async (): Promise<string> => {
    const { data, error } = await apiRequest<{ success: boolean; data?: { vapid_public_key: string } }>('/ess/push-vapid');
    const key = data?.data?.vapid_public_key || (data as Record<string, unknown>)?.vapid_public_key as string | undefined;
    if (error || !key) {
      throw new Error('VAPID public key not available: ' + (error || 'empty'));
    }
    return key;
  }, []);

  /**
   * Save push subscription to backend.
   */
  const saveSubscription = useCallback(async (subscription: PushSubscription) => {
    const sub = subscription.toJSON();
    const payload = {
      endpoint: sub.endpoint,
      keys: sub.keys, // { p256dh, auth }
    };

    const { error } = await apiRequest('/ess/push-subscribe', {
      method: 'POST',
      body: JSON.stringify(payload),
    });

    if (error) {
      logger.error('Failed to save push subscription:', error);
      throw error;
    }
  }, []);

  /**
   * Core subscribe flow: SW → permission → subscribe → save.
   */
  const subscribe = useCallback(async () => {
    if (stateRef.current.loading) return false;

    // Check support
    if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
      stateRef.current.supported = false;
      return false;
    }
    stateRef.current.supported = true;
    stateRef.current.loading = true;

    try {
      // 1. Register SW
      const reg = await registerSW();
      if (!reg) {
        logger.warn('Push: could not register service worker');
        return false;
      }

      // 2. Check / request permission
      let perm = Notification.permission;
      if (perm === 'default') {
        perm = await Notification.requestPermission();
      }
      stateRef.current.permission = perm;

      if (perm !== 'granted') {
        logger.info('Push: notification permission', perm);
        return false;
      }

      // 3. Get VAPID key
      const vapidKey = await fetchVapidKey();

      // 4. Subscribe
      const subscription = await reg.pushManager.subscribe({
        userVisibleOnly: true,
        applicationServerKey: urlBase64ToUint8Array(vapidKey),
      });

      // 5. Save to backend
      await saveSubscription(subscription);

      // 6. Mark as subscribed
      stateRef.current.subscribed = true;
      stateRef.current.loading = false;
      try { localStorage.setItem(SUBSCRIBED_KEY, '1'); } catch { /* */ }

      logger.info('Push: subscribed successfully');
      return true;
    } catch (err) {
      logger.error('Push: subscribe failed:', err);
      stateRef.current.loading = false;
      stateRef.current.subscribed = false;
      return false;
    }
  }, [registerSW, fetchVapidKey, saveSubscription]);

  /**
   * Auto-initialize: if previously subscribed, re-subscribe silently.
   * Only runs once per mount.
     */
  useEffect(() => {
    if (!employeeId || initDoneRef.current) return;
    initDoneRef.current = true;

    const init = async () => {
      // Check basic support
      if (!('serviceWorker' in navigator) || !('PushManager' in window)) {
        stateRef.current.supported = false;
        return;
      }
      stateRef.current.supported = true;
      stateRef.current.permission = Notification.permission;

      // If never subscribed before, don't auto-subscribe (wait for permission flow)
      // If previously subscribed, re-subscribe silently
      const wasSubscribed = localStorage.getItem(SUBSCRIBED_KEY) === '1';
      const permissionGranted = Notification.permission === 'granted';

      if (wasSubscribed || permissionGranted) {
        // Re-subscribe (handles SW update, new VAPID key, etc.)
        await subscribe();
      }
    };

    // Small delay to not block initial render
    setTimeout(init, 2000);
  }, [employeeId, subscribe]);

  // Listen for permission grant from post-install dialog
  useEffect(() => {
    const handler = () => {
      // If permission was just granted, try to subscribe
      if (Notification.permission === 'granted') {
        subscribe();
      }
    };
    window.addEventListener('ess:permissions-requested', handler);
    return () => window.removeEventListener('ess:permissions-requested', handler);
  }, [subscribe]);

  return {
    subscribe,
    isSupported: () => stateRef.current.supported,
    isSubscribed: () => stateRef.current.subscribed,
    getPermission: () => stateRef.current.permission,
  };
}
