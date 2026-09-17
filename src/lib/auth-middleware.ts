import { NextRequest } from 'next/server'
import { verifyAccessToken, type AccessTokenPayload } from './auth'
import { db } from './db'

export interface AuthResult {
  employee: AccessTokenPayload
  cache: {
    id: string
    employeeId: string
    employeeCode: string
    fullName: string
    mobileNumber: string
    designation: string | null
    department: string | null
    role: string
    appRole: string
    unitId: number | null
    unitName: string | null
    city: string | null
    state: string | null
    clientName: string | null
    clientId: number | null
    profilePicUrl: string | null
    hasPin: boolean
  }
}

/**
 * Require authentication on an API route.
 * Reads the Authorization: Bearer <token> header, verifies the JWT,
 * and returns the employee payload + cached data.
 * Throws an error-like response that should be returned directly.
 */
export async function requireAuth(request: NextRequest): Promise<AuthResult> {
  const authHeader = request.headers.get('authorization')

  if (!authHeader || !authHeader.startsWith('Bearer ')) {
    throw new AuthError('Missing or invalid Authorization header', 401)
  }

  const token = authHeader.slice(7) // Remove 'Bearer '

  let payload: AccessTokenPayload
  try {
    payload = verifyAccessToken(token)
  } catch (err) {
    const message = err instanceof Error ? err.message : 'Invalid token'
    throw new AuthError(message, 401)
  }

  // Verify employee still exists and is active via cache
  const cache = await db.essEmployeeCache.findUnique({
    where: { employeeId: payload.employeeId },
  })

  if (!cache) {
    throw new AuthError('Employee not found', 401)
  }

  return {
    employee: payload,
    cache: {
      id: cache.id,
      employeeId: cache.employeeId,
      employeeCode: cache.employeeCode,
      fullName: cache.fullName,
      mobileNumber: cache.mobileNumber,
      designation: cache.designation,
      department: cache.department,
      role: cache.role,
      appRole: cache.appRole,
      unitId: cache.unitId,
      unitName: cache.unitName,
      city: cache.city,
      state: cache.state,
      clientName: cache.clientName,
      clientId: cache.clientId,
      profilePicUrl: cache.profilePicUrl,
      hasPin: !!cache.pin,
    },
  }
}

export class AuthError extends Error {
  statusCode: number

  constructor(message: string, statusCode: number) {
    super(message)
    this.statusCode = statusCode
    this.name = 'AuthError'
  }
}
