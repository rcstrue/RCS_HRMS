# ESS Login 500 Fix — 3 Bugs Found

All 3 bugs are in `api/ess/login.php` and `api/ess/example.config.php`.
No other files need touching.

---

## Bug 1 — `SimpleJWT::encode()` called with wrong argument type → Fatal Error

**Root cause:**
`SimpleJWT::encode()` signature is:
```php
public static function encode(array $payload, int $expirySeconds = 86400): string
```
The second argument is typed as `int` (seconds). The previous fix changed
the call to pass `JWT_SECRET` (a string) as the second argument. PHP
throws a fatal `TypeError` on this — causing the 500.

The class already initialises with the secret via `SimpleJWT::init(JWT_SECRET)`
at the bottom of `config.php` — so the secret is already loaded. The encode
call just needs the expiry seconds, nothing else.

**File:** `api/ess/login.php`

FIND:
```php
    $token = SimpleJWT::encode(array(
        'employee_id' => $employeeId,
        'role'        => $role,
        'full_name'   => $employee['full_name'],
        'iat'         => time(),
        'exp'         => time() + JWT_EXPIRY,
    ), JWT_SECRET);
```
REPLACE:
```php
    $token = SimpleJWT::encode(array(
        'employee_id' => $employeeId,
        'role'        => $role,
        'full_name'   => $employee['full_name'],
    ), JWT_EXPIRY);
```
*(The class sets `iat` and `exp` itself inside `encode()` — don't pass them
in the payload, they'll be doubled. The secret is already set via `init()`.)*

---

## Bug 2 — `SimpleJWT::encode()` overwrites `iat`/`exp` even if passed in payload

**Root cause:**
Inside `encode()` in `example.config.php`:
```php
public static function encode(array $payload, int $expirySeconds = 86400): string
{
    $now = time();
    $payload['iat'] = $now;           // always overwrites
    $payload['exp'] = $now + $expirySeconds;  // always overwrites
```
If login.php passes `iat` and `exp` in the payload AND passes `JWT_EXPIRY`
as seconds, the class overwrites them anyway. So the payload fields are
redundant — remove them from the call (done in Bug 1 fix above).

No change needed to `example.config.php` — the class behaviour is correct,
the call was wrong.

---

## Bug 3 — `refresh.php` passes wrong argument to `SimpleJWT::decode()`

**File:** `api/ess/refresh.php`

FIND:
```php
    $payload = SimpleJWT::decode($token, allowExpired: true);
```

Check `SimpleJWT::decode()` signature in `example.config.php`:
```php
public static function decode(string $token, bool $allowExpired = false): ?array
```
The named argument `allowExpired:` works in PHP 8.0+ — but confirm your
server is on PHP 8.0+. If it's PHP 7.4, named arguments don't exist and
this will throw a parse error on any request that hits refresh.php.

REPLACE (safe for both PHP 7.4 and 8.0+):
```php
    $payload = SimpleJWT::decode($token, true);
```

---

## Summary — which file, what to change

| File | Change |
|---|---|
| `api/ess/login.php` | Remove `JWT_SECRET` as 2nd arg to `encode()`, remove `iat`/`exp` from payload, pass `JWT_EXPIRY` as 2nd arg |
| `api/ess/refresh.php` | Change `allowExpired: true` → `true` (positional arg) |

---

## Test after deploying

```
POST https://join.rcsfacility.com/api/ess/login
Content-Type: application/json
X-API-KEY: RCS_HRMS_SECURE_KEY_982374982374

{"mobile_number": "9999999999", "pin": "1234"}
```
Expected: `200 { success: true, data: { token: "...", employee: {...} } }`

If still 500 — check the PHP error log on the server. The `error_log()`
in the catch block writes the exact line and exception message there.
On LiteSpeed hosting it's usually at:
`~/logs/error_log` or `~/public_html/error_log`
