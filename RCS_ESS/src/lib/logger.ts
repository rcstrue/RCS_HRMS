// ══════════════════════════════════════════════════════════════
// ESS Logger — thin wrapper around console that respects environment
// ══════════════════════════════════════════════════════════════
//
// In production builds (import.meta.env.PROD === true):
//   - log(), info(), debug()  → no-op (silenced to keep the console clean +
//     avoid leaking internal state to anyone who opens DevTools).
//   - error(), warn()         → passed through to console (these are useful
//     for diagnosing production issues and are retained).
//
// In development builds (import.meta.env.DEV === true):
//   - all methods pass through to console with a [ESS] prefix.
//
// Usage:
//   import { logger } from '@/lib/logger';
//   logger.error('Failed to fetch', err);
//   logger.log('Debug value', value);   // no-op in production
//

const PREFIX = '[ESS]';
const isProd = typeof import.meta !== 'undefined'
  && (import.meta as Record<string, Record<string, unknown>>).env?.PROD === true;

export const logger = {
  /** Verbose logging — no-op in production. Use for dev-only diagnostics. */
  log: isProd
    ? () => {}
    : (...args: unknown[]) => console.log(PREFIX, ...args),

  /** Info-level logging — no-op in production. */
  info: isProd
    ? () => {}
    : (...args: unknown[]) => console.info(PREFIX, ...args),

  /** Debug-level logging — no-op in production. */
  debug: isProd
    ? () => {}
    : (...args: unknown[]) => console.debug(PREFIX, ...args),

  /** Error logging — ALWAYS active (useful for production diagnosis). */
  error: (...args: unknown[]) => console.error(PREFIX, ...args),

  /** Warning logging — ALWAYS active. */
  warn: (...args: unknown[]) => console.warn(PREFIX, ...args),
};
