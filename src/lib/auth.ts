import crypto from 'node:crypto'

// --- Configuration ---
const JWT_SECRET = process.env.JWT_SECRET || 'dev-secret-change-in-production'

// --- JWT Access Token (HMAC-SHA256, no external library) ---

function base64urlEncode(data: string): string {
  return Buffer.from(data).toString('base64url')
}

function base64urlDecode(str: string): string {
  return Buffer.from(str, 'base64url').toString('utf-8')
}

export interface AccessTokenPayload {
  employeeId: string
  employeeCode: string
  mobileNumber: string
  role: string
  appRole: string
  iat: number
  exp: number
}

/**
 * Create a short-lived JWT access token (1 hour).
 * Uses HMAC-SHA256 — no external JWT library needed.
 */
export function signAccessToken(payload: Omit<AccessTokenPayload, 'iat' | 'exp'>): string {
  const now = Math.floor(Date.now() / 1000)
  const exp = now + 3600 // 1 hour

  const header = base64urlEncode(JSON.stringify({ alg: 'HS256', typ: 'JWT' }))
  const body = base64urlEncode(
    JSON.stringify({ ...payload, iat: now, exp })
  )

  const signature = crypto
    .createHmac('sha256', JWT_SECRET)
    .update(`${header}.${body}`)
    .digest('base64url')

  return `${header}.${body}.${signature}`
}

/**
 * Verify and decode a JWT access token.
 * Returns the payload if valid, throws Error if invalid/expired.
 */
export function verifyAccessToken(token: string): AccessTokenPayload {
  const parts = token.split('.')
  if (parts.length !== 3) {
    throw new Error('Invalid token format')
  }

  const [header, body, signature] = parts

  // Verify signature
  const expectedSig = crypto
    .createHmac('sha256', JWT_SECRET)
    .update(`${header}.${body}`)
    .digest('base64url')

  if (!crypto.timingSafeEqual(Buffer.from(signature), Buffer.from(expectedSig))) {
    throw new Error('Invalid token signature')
  }

  // Decode payload
  const payload = JSON.parse(base64urlDecode(body)) as AccessTokenPayload

  // Check expiration
  if (payload.exp < Math.floor(Date.now() / 1000)) {
    throw new Error('Token expired')
  }

  return payload
}

// --- Refresh Token (random crypto string, stored in DB) ---

/**
 * Generate a cryptographically secure random refresh token.
 * This is NOT a JWT — it's a random hex string looked up in the DB.
 */
export function generateRefreshToken(): string {
  return crypto.randomBytes(64).toString('hex') // 128-char hex string
}

/**
 * Calculate the expiry date for a refresh token (90 days from now).
 */
export function getRefreshTokenExpiry(): Date {
  return new Date(Date.now() + 90 * 24 * 60 * 60 * 1000)
}
