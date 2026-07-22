import { logger } from "@/lib/logger";
// API Configuration - direct calls to backend server
//
// SECURITY (Round 3): API_BASE_URL and API_KEY are now EXPORTED so other modules
// (e.g. ess-auth.ts proactive refresh) can reuse the same single source of truth
// instead of hardcoding their own copy + a dangerous fallback secret.
//
// VITE_API_URL: set in .env for the target backend. Falls back to the production
//   host so the app works out-of-the-box for the live deployment.
// VITE_API_KEY: set in .env (build-time). Falls back to EMPTY STRING — never a
//   hardcoded secret. The server validates via hash_equals; an empty key will be
//   rejected, which is the safe failure mode.
export const API_BASE_URL =
  (import.meta as Record<string, Record<string, string>>).env?.VITE_API_URL
  || 'https://join.rcsfacility.com';

export const API_KEY =
  (import.meta as Record<string, Record<string, string>>).env?.VITE_API_KEY
  || '';

// Guard against duplicate session-expired toasts
let _sessionExpiredFired = false;

/** Reset the session-expired guard (call after successful login) */
export function resetSessionExpiredGuard() { _sessionExpiredFired = false; }

// ── Silent token-refresh state ──────────────────────────────────
let _refreshPromise: Promise<string | null> | null = null;

/**
 * Attempt to refresh the ESS JWT token silently.
 * Returns the new token on success, or null on failure.
 * Concurrent 401s share the same in-flight refresh promise.
 */
async function tryRefreshToken(): Promise<string | null> {
  if (_refreshPromise) return _refreshPromise;

  _refreshPromise = (async () => {
    try {
      // R11: token is in the HttpOnly cookie — just POST to refresh.
      // refresh.php reads from the cookie and returns a new token (also set as cookie).
      const resp = await fetch(`${API_BASE_URL}/api/ess/refresh.php`, {
        method: 'POST',
        headers: { 'Content-Type': 'application/json', 'X-API-KEY': API_KEY },
        body: JSON.stringify({}), // token is in the cookie, not the body
        credentials: 'include', // send the ess_jwt HttpOnly cookie
      });

      if (!resp.ok) return null;

      const json = await resp.json();
      const newToken: string | undefined = json?.data?.token;
      if (!newToken) return null;

      // R11: Don't store in localStorage — it's in the HttpOnly cookie.
      // Return a non-null value so the caller knows refresh succeeded.
      return newToken;
    } catch {
      return null;
    } finally {
      _refreshPromise = null;
    }
  })();

  return _refreshPromise;
}

// Files base URL for displaying uploaded images
export const FILES_BASE_URL = `${API_BASE_URL}/uploads`;

// Helper to get full file URL from path returned by server
// Server returns paths like "/uploads/profile/xxx.jpg" or "profile/xxx.jpg"
// We need to convert to "https://join.rcsfacility.com/uploads/profile/xxx.jpg"
export function getFileUrl(path: string | null | undefined): string | null {
  if (!path) return null;

  // If already a full URL, return as-is
  if (path.startsWith('http://') || path.startsWith('https://')) {
    return path;
  }

  // Remove leading /uploads/ if present (server sometimes returns "/uploads/profile/xxx.jpg")
  const cleanPath = path.replace(/^\/uploads\//, '');

  // Construct full URL: https://join.rcsfacility.com/uploads/profile/xxx.jpg
  return `${FILES_BASE_URL}/${cleanPath}`;
}

// API request helper - direct fetch to backend
export async function apiRequest<T>(
  endpoint: string,
  options: RequestInit = {}
): Promise<{ data: T | null; error: string | null }> {
  try {
    // R11 final: cookie-only auth. The ESS JWT is in the HttpOnly cookie
    // (set by login.php/refresh.php), sent via credentials:'include'.
    // localStorage token storage is REMOVED. All users must re-login once
    // to get the cookie set.
    const adminToken = localStorage.getItem('admin_token');

    const headers: Record<string, string> = {
      'Content-Type': 'application/json',
      'X-API-KEY': API_KEY,
    };
    // Admin panel uses admin_token in Authorization header (separate auth flow)
    if (adminToken) {
      headers['Authorization'] = `Bearer ${adminToken}`;
    }

    // Set X-Employee-ID from ess_employee session (for server-side logging)
    const essSession = localStorage.getItem('ess_employee');
    if (essSession) {
      try {
        const parsed = JSON.parse(essSession);
        if (parsed?.employee?.id) {
          headers['X-Employee-ID'] = String(parsed.employee.id);
        }
      } catch { /* ignore */ }
    }

    // Custom headers override auto-resolved values (e.g., admin token vs ess token)
    Object.assign(headers, options.headers as Record<string, string>);

    const response = await fetch(`${API_BASE_URL}/api${endpoint}`, {
      ...options,
      headers,
      credentials: 'include', // Round 10: send the ess_jwt HttpOnly cookie
    });

    // Check if response is JSON
    const contentType = response.headers.get('content-type');
    const responseText = await response.text();
    
    let data;
    if (contentType && contentType.includes('application/json')) {
      if (!responseText.trim()) {
        if (response.ok) {
          return { data: null, error: null };
        }
        return { data: null, error: 'Empty server response. Please try again.' };
      }

      try {
        data = JSON.parse(responseText);
      } catch {
        logger.error(`Failed to parse JSON response for ${endpoint} (status ${response.status}):`, responseText.substring(0, 200));
        return { data: null, error: 'Invalid server response. Please try again.' };
      }
    } else {
      // Response is HTML or something else
      logger.error('Non-JSON response received:', responseText.substring(0, 500));
      logger.error('Response status:', response.status);
      logger.error('Content-Type:', contentType);
      logger.error('Endpoint:', endpoint);
      
      if (response.status === 404) {
        return { data: null, error: 'API endpoint not found. Please contact support.' };
      }
      if (response.status === 403) {
        return { data: null, error: 'Access denied. Please check your permissions.' };
      }
      if (response.status === 500) {
        return { data: null, error: 'Server error. Please try again later.' };
      }
      return { data: null, error: 'Server is temporarily unavailable. Please try again.' };
    }

    if (!response.ok) {
      // ── Token expiry / invalid → try silent refresh first ──
      if (response.status === 401) {
        const isEss = localStorage.getItem('ess_employee'); // cookie-only: check session exists
        if (isEss) {
          // Don't nuke tokens if user is in force PIN change flow (has_custom_pin=false)
          // This prevents cascade failure on mobile where PIN change API call might race
          try {
            const sessionStr = localStorage.getItem('ess_employee');
            if (sessionStr) {
              const s = JSON.parse(sessionStr);
              if (!s.has_custom_pin) {
                // User is changing PIN — just return the error, don't clear tokens
                return { data: null, error: data?.error || data?.message || 'Authentication error. Please try again.' };
              }
            }
          } catch { /* parse error — proceed with normal clear */ }

          // ── Attempt silent token refresh ──
          const newToken = await tryRefreshToken();
          if (newToken) {
            // Retry the original request with the new token
            const retryHeaders: Record<string, string> = {
              'Content-Type': 'application/json',
              'X-API-KEY': API_KEY,
              'Authorization': `Bearer ${newToken}`,
            };
            if (essSession) {
              try {
                const parsed = JSON.parse(essSession);
                if (parsed?.employee?.id) retryHeaders['X-Employee-ID'] = String(parsed.employee.id);
              } catch { /* ignore */ }
            }
            Object.assign(retryHeaders, options.headers as Record<string, string>);

            const retryResp = await fetch(`${API_BASE_URL}/api${endpoint}`, {
              ...options,
              headers: retryHeaders,
              credentials: 'include', // Round 10: send the ess_jwt HttpOnly cookie
            });
            const retryText = await retryResp.text();
            if (retryResp.ok) {
              let retryData;
              try { retryData = JSON.parse(retryText); } catch { retryData = retryText; }
              return { data: retryData as T, error: null };
            }
            // Refresh succeeded but retry still failed — fall through to logout
          }

          // Refresh failed or retry failed — clear session
          localStorage.removeItem('ess_employee'); // cookie-only: just remove session
          // Dispatch only ONCE to prevent toast spam from concurrent 401s
          if (!_sessionExpiredFired) {
            _sessionExpiredFired = true;
            window.dispatchEvent(new CustomEvent('ess:session-expired'));
          }
          return { data: null, error: data?.error || data?.message || 'Session expired. Please login again.' };
        }
      }
      return { data: null, error: data?.error || data?.message || 'Request failed' };
    }

    return { data: data as T, error: null };
  } catch (error) {
    logger.error('API Error:', error);
    return { data: null, error: 'Network error. Please check your connection.' };
  }
}

// File upload helper
export async function uploadFile(
  file: File,
  folder: string = 'documents'
): Promise<{ url: string | null; error: string | null }> {
  try {
    const base64Data = await fileToBase64(file);
    return uploadBase64Image(base64Data, file.name, folder);

  } catch (error) {
    logger.error('Upload Error:', error);
    return { url: null, error: 'Upload failed. Please try again.' };
  }
}

// Base64 image upload helper (for camera captures)
export async function uploadBase64Image(
  base64Data: string,
  filename: string,
  folder: string = 'documents'
): Promise<{ url: string | null; error: string | null }> {
  try {
    const response = await fetch(`${API_BASE_URL}/api/upload/base64`, {
      method: 'POST',
      credentials: 'include', // Round 10: send the ess_jwt HttpOnly cookie
      headers: (() => {
        const h: Record<string, string> = { 'Content-Type': 'application/json', 'X-API-KEY': API_KEY };
        const t = localStorage.getItem('admin_token'); // cookie-only: ESS auth via cookie
        if (t) h['Authorization'] = `Bearer ${t}`;
        const ess = localStorage.getItem('ess_employee');
        if (ess) {
          try {
            const parsed = JSON.parse(ess);
            if (parsed?.employee?.id) h['X-Employee-ID'] = String(parsed.employee.id);
            // R11: don't set Authorization from session.token — ESS auth is via cookie
          } catch { /* invalid session */ }
        }
        return h;
      })(),
      body: JSON.stringify({ base64Data, filename, folder }),
    });

    // Check if response is JSON
    const contentType = response.headers.get('content-type');
    const responseText = await response.text();
    
    let data;
    if (contentType && contentType.includes('application/json')) {
      if (!responseText.trim()) {
        return { url: null, error: 'Empty server response. Please try again.' };
      }

      try {
        data = JSON.parse(responseText);
      } catch {
        logger.error(`Failed to parse JSON response for /upload/base64 (status ${response.status}):`, responseText.substring(0, 200));
        return { url: null, error: 'Invalid server response. Please try again.' };
      }
    } else {
      // Response is HTML or something else
      logger.error('Non-JSON response from upload:', responseText.substring(0, 500));
      return { url: null, error: 'Server error. Please try again later.' };
    }

    if (!response.ok || data?.error) {
      return { url: null, error: data?.error || 'Upload failed' };
    }

    return { url: data?.url || null, error: null };
  } catch (error) {
    logger.error('Upload Error:', error);
    return { url: null, error: 'Upload failed. Please try again.' };
  }
}

// Helper to convert File to base64
function fileToBase64(file: File): Promise<string> {
  return new Promise((resolve, reject) => {
    const reader = new FileReader();
    reader.onload = () => resolve(reader.result as string);
    reader.onerror = reject;
    reader.readAsDataURL(file);
  });
}
