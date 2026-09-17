import { db } from '@/lib/db'
import { Prisma } from '@prisma/client'

interface RateLimitResult {
  allowed: boolean
  lockedUntil: Date | null
  remainingAttempts: number
}

/**
 * Check rate limiting for a given username (mobile number).
 * Mirrors the PHP backend's progressive lockout:
 *   5 failures  → 15 min lockout
 *   10 failures → 1 hr lockout
 *   20 failures → 24 hr lockout
 */
export async function checkRateLimit(
  username: string,
  ip?: string
): Promise<RateLimitResult> {
  const attempt = await db.loginAttempt.findUnique({
    where: { username },
  })

  if (!attempt) {
    return { allowed: true, lockedUntil: null, remainingAttempts: 5 }
  }

  // If currently locked, check if lockout has expired
  if (attempt.lockedUntil && attempt.lockedUntil > new Date()) {
    return {
      allowed: false,
      lockedUntil: attempt.lockedUntil,
      remainingAttempts: 0,
    }
  }

  // If lockout expired, allow — but keep the attempt count for progressive tracking
  if (attempt.lockedUntil && attempt.lockedUntil <= new Date()) {
    return { allowed: true, lockedUntil: null, remainingAttempts: 5 }
  }

  // Calculate remaining attempts based on current count
  const remaining = getMaxAttempts(attempt.attempts) - attempt.attempts

  return {
    allowed: remaining > 0,
    lockedUntil: null,
    remainingAttempts: Math.max(0, remaining),
  }
}

/**
 * Record a failed login attempt and return the updated lockout status.
 */
export async function recordFailedAttempt(
  username: string,
  ip?: string
): Promise<RateLimitResult> {
  const existing = await db.loginAttempt.findUnique({
    where: { username },
  })

  const now = new Date()
  let attempts: number
  let lockedUntil: Date | null = null

  if (existing) {
    attempts = existing.attempts + 1
  } else {
    attempts = 1
  }

  // Determine lockout based on attempt count
  if (attempts >= 20) {
    lockedUntil = new Date(now.getTime() + 24 * 60 * 60 * 1000) // 24 hr
  } else if (attempts >= 10) {
    lockedUntil = new Date(now.getTime() + 60 * 60 * 1000) // 1 hr
  } else if (attempts >= 5) {
    lockedUntil = new Date(now.getTime() + 15 * 60 * 1000) // 15 min
  }

  const data: Prisma.LoginAttemptUpsertArgs['data'] = {
    username,
    ip,
    attempts,
    lastAttempt: now,
    lockedUntil,
  }

  await db.loginAttempt.upsert({
    where: { username },
    update: data,
    create: data,
  })

  return {
    allowed: false,
    lockedUntil,
    remainingAttempts: Math.max(0, getMaxAttempts(attempts) - attempts),
  }
}

/**
 * Clear all failed login attempts for a username on successful login.
 */
export async function clearFailedAttempts(username: string): Promise<void> {
  try {
    await db.loginAttempt.delete({
      where: { username },
    })
  } catch {
    // Already deleted, that's fine
  }
}

// --- Helpers ---

function getMaxAttempts(currentAttempts: number): number {
  if (currentAttempts >= 20) return 20
  if (currentAttempts >= 10) return 10
  if (currentAttempts >= 5) return 5
  return 5
}
