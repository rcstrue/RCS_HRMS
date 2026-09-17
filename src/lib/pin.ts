/**
 * PIN hashing and verification utilities.
 * Uses Bun.password (bcrypt-based) since the project runs on Bun.
 */

/**
 * Hash a PIN string using Bun's built-in bcrypt implementation.
 */
export async function hashPin(pin: string): Promise<string> {
  return await Bun.password.hash(pin, {
    algorithm: 'bcrypt',
    cost: 10,
  })
}

/**
 * Verify a PIN against a stored hash.
 */
export async function verifyPin(pin: string, hash: string): Promise<boolean> {
  return await Bun.password.verify(pin, hash)
}
