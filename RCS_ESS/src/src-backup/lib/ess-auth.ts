// ══════════════════════════════════════════════════════════════
// ESS Token Manager — JWT token lifecycle management
// ══════════════════════════════════════════════════════════════

const ESS_TOKEN_KEY = 'ess_token';
const ESS_SESSION_KEY = 'ess_employee';
const TOKEN_BUFFER_MS = 60_000; // Refresh 60s before expiry
const PROACTIVE_REFRESH_INTERVAL_MS = 4 * 60_000; // Check every 4 minutes

let _proactiveRefreshTimer: ReturnType<typeof setInterval> | null = null;

export interface JWTPayload {
  employee_id: number;
  employee_code: string;
  role: string;
  iat: number;
  exp: number;
}

// ── Token Storage ──────────────────────────────────────────

export function storeEssToken(token: string): void {
  localStorage.setItem(ESS_TOKEN_KEY, token);
}

export function getEssToken(): string | null {
  return localStorage.getItem(ESS_TOKEN_KEY);
}

export function clearEssAuth(): void {
  localStorage.removeItem(ESS_TOKEN_KEY);
  localStorage.removeItem(ESS_SESSION_KEY);
}

// ── Token Validation ───────────────────────────────────────

/**
 * Decode JWT payload without verification (client-side).
 * Actual verification happens on the PHP server.
 */
export function decodeJWTPayload(token: string): JWTPayload | null {
  try {
    const parts = token.split('.');
    if (parts.length !== 3) return null;
    const base64Url = parts[1].replace(/-/g, '+').replace(/_/g, '/');
    const base64 = atob(base64Url);
    const json = JSON.parse(base64);
    return json as JWTPayload;
  } catch {
    return null;
  }
}

export function isTokenExpired(token: string): boolean {
  const payload = decodeJWTPayload(token);
  if (!payload) return true;
  return Date.now() >= (payload.exp * 1000) - TOKEN_BUFFER_MS;
}

export function isTokenValid(token: string): boolean {
  return !!token && !isTokenExpired(token);
}

export function getTokenExpiryTime(token: string): Date | null {
  const payload = decodeJWTPayload(token);
  if (!payload) return null;
  return new Date(payload.exp * 1000);
}

// ── Rate Limit State (client-side tracking) ────────────────

const RATE_LIMIT_KEY = 'ess_login_attempts';

interface RateLimitState {
  attempts: number;
  firstAttemptAt: number;
  lockedUntil: number | null;
}

function getRateLimitState(): RateLimitState {
  try {
    const raw = localStorage.getItem(RATE_LIMIT_KEY);
    if (!raw) return { attempts: 0, firstAttemptAt: 0, lockedUntil: null };
    return JSON.parse(raw) as RateLimitState;
  } catch {
    return { attempts: 0, firstAttemptAt: 0, lockedUntil: null };
  }
}

function saveRateLimitState(state: RateLimitState): void {
  localStorage.setItem(RATE_LIMIT_KEY, JSON.stringify(state));
}

/**
 * Record a failed login attempt.
 * Returns rate limit info for UI display.
 */
export function recordFailedAttempt(): {
  remainingAttempts: number;
  locked: boolean;
  lockoutRemaining: number; // seconds
  cooldownRemaining: number; // seconds until next attempt allowed
} {
  const state = getRateLimitState();
  const now = Date.now();
  const ONE_MINUTE = 60_000;
  const LOCKOUT_DURATION = 30 * ONE_MINUTE; // 30 minutes

  // Reset if cooldown window passed (1 minute)
  if (now - state.firstAttemptAt > ONE_MINUTE) {
    state.attempts = 1;
    state.firstAttemptAt = now;
  } else {
    state.attempts += 1;
  }

  // Check for account lock (10 failures triggers 30-min lockout)
  if (state.attempts >= 10) {
    state.lockedUntil = now + LOCKOUT_DURATION;
    saveRateLimitState(state);
    return {
      remainingAttempts: 0,
      locked: true,
      lockoutRemaining: Math.ceil(LOCKOUT_DURATION / 1000),
      cooldownRemaining: Math.ceil(LOCKOUT_DURATION / 1000),
    };
  }

  saveRateLimitState(state);
  const attemptsInWindow = state.attempts;
  const windowElapsed = now - state.firstAttemptAt;
  const cooldownRemaining = Math.max(0, Math.ceil((ONE_MINUTE - windowElapsed) / 1000));

  return {
    remainingAttempts: Math.max(0, 5 - attemptsInWindow),
    locked: false,
    lockoutRemaining: 0,
    cooldownRemaining,
  };
}

/**
 * Clear rate limit state (called on successful login).
 */
export function clearRateLimit(): void {
  localStorage.removeItem(RATE_LIMIT_KEY);
}

/**
 * Get current rate limit status (for UI display on mount).
 */
export function getRateLimitStatus(): {
  remainingAttempts: number;
  locked: boolean;
  lockoutRemaining: number;
  cooldownRemaining: number;
} {
  const state = getRateLimitState();
  const now = Date.now();
  const ONE_MINUTE = 60_000;
  const LOCKOUT_DURATION = 30 * ONE_MINUTE;

  // Check if locked
  if (state.lockedUntil && now < state.lockedUntil) {
    return {
      remainingAttempts: 0,
      locked: true,
      lockoutRemaining: Math.ceil((state.lockedUntil - now) / 1000),
      cooldownRemaining: Math.ceil((state.lockedUntil - now) / 1000),
    };
  }

  // Lock expired — reset
  if (state.lockedUntil && now >= state.lockedUntil) {
    saveRateLimitState({ attempts: 0, firstAttemptAt: 0, lockedUntil: null });
    return { remainingAttempts: 5, locked: false, lockoutRemaining: 0, cooldownRemaining: 0 };
  }

  // Within cooldown window
  if (state.firstAttemptAt && now - state.firstAttemptAt < ONE_MINUTE) {
    const windowElapsed = now - state.firstAttemptAt;
    const cooldownRemaining = Math.max(0, Math.ceil((ONE_MINUTE - windowElapsed) / 1000));
    return {
      remainingAttempts: Math.max(0, 5 - state.attempts),
      locked: false,
      lockoutRemaining: 0,
      cooldownRemaining,
    };
  }

  return { remainingAttempts: 5, locked: false, lockoutRemaining: 0, cooldownRemaining: 0 };
}

// ── Proactive Token Refresh ───────────────────────────────────

/**
 * Silently refresh the JWT token before it expires.
 * Call this after login to keep the session alive.
 * Returns true if refresh succeeded, false otherwise.
 */
export async function proactiveRefresh(): Promise<boolean> {
  const token = getEssToken();
  if (!token) return false;

  // Only refresh if token is nearing expiry (within buffer window)
  if (!isTokenExpired(token)) return true; // still fresh

  try {
    const API_BASE = 'https://join.rcsfacility.com';
    const API_KEY = typeof import.meta !== 'undefined' && (import.meta as Record<string, Record<string, string>>).env?.VITE_API_KEY
      ? (import.meta as Record<string, Record<string, string>>).env.VITE_API_KEY
      : 'RCS_HRMS_SECURE_KEY_982374982374';

    const resp = await fetch(`${API_BASE}/api/ess/refresh.php`, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-API-KEY': API_KEY },
      body: JSON.stringify({ token }),
    });

    if (!resp.ok) return false;

    const json = await resp.json();
    const newToken: string | undefined = json?.data?.token;
    if (!newToken) return false;

    // Persist
    localStorage.setItem(ESS_TOKEN_KEY, newToken);
    const essSession = localStorage.getItem(ESS_SESSION_KEY);
    if (essSession) {
      try {
        const parsed = JSON.parse(essSession);
        parsed.token = newToken;
        localStorage.setItem(ESS_SESSION_KEY, JSON.stringify(parsed));
      } catch { /* */ }
    }

    return true;
  } catch {
    return false;
  }
}

/**
 * Start the proactive refresh timer.
 * Checks every PROACTIVE_REFRESH_INTERVAL_MS and refreshes when near expiry.
 * Call after successful login; call stopProactiveRefresh() on logout.
 */
export function startProactiveRefresh(): void {
  stopProactiveRefresh(); // clear any existing timer
  _proactiveRefreshTimer = setInterval(() => {
    proactiveRefresh().catch(() => { /* silent */ });
  }, PROACTIVE_REFRESH_INTERVAL_MS);
}

/**
 * Stop the proactive refresh timer.
 * Call on logout to prevent refresh attempts with a cleared token.
 */
export function stopProactiveRefresh(): void {
  if (_proactiveRefreshTimer !== null) {
    clearInterval(_proactiveRefreshTimer);
    _proactiveRefreshTimer = null;
  }
}
