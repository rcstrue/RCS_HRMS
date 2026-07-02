# ESS Login Fix — 2 Bugs

Both bugs are in `api/ess/login.php`. No other files need changing.

---

## Bug 1 — `verifyPin()` called before `helpers.php` is loaded

**Root cause:**
`login.php` calls `verifyPin()` at line 99, but only loads `helpers.php`
at line 119 (after the PIN check). `verifyPin()` lives in `helpers.php`
— so PHP throws a fatal error before login ever completes.

**File:** `api/ess/login.php`

FIND (top of file, after the opening `<?php` block):
```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
```
REPLACE:
```php
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/security-headers.php';
require_once __DIR__ . '/helpers.php';
```

Then FIND (further down, where helpers.php is currently loaded too late):
```php
    // Use centralized role determination (app_role as primary source)
    require_once __DIR__ . '/helpers.php';
    $role = determineEssRole($employee);
```
REPLACE (remove the now-duplicate require, keep the function call):
```php
    // Use centralized role determination (app_role as primary source)
    $role = determineEssRole($employee);
```

---

## Bug 2 — `SimpleJWT::encode()` signature mismatch

**Root cause:**
`login.php` calls:
```php
$token = SimpleJWT::encode(array(...), JWT_EXPIRY);
```
But `SimpleJWT::encode()` in `example.config.php` takes `($payload, $secret)`
— the second argument is the JWT secret, not the expiry. The expiry is
embedded inside the payload as `exp`. Passing `JWT_EXPIRY` (an integer like
`3600`) as the secret means every token is signed with `'3600'` instead of
the real secret, so `decode()` rejects every token immediately.

**File:** `api/ess/login.php`

FIND:
```php
    $token = SimpleJWT::encode(array(
        'employee_id' => $employeeId,
        'role' => $role,
        'full_name' => $employee['full_name']
    ), JWT_EXPIRY);
```
REPLACE:
```php
    $token = SimpleJWT::encode(array(
        'employee_id' => $employeeId,
        'role'        => $role,
        'full_name'   => $employee['full_name'],
        'iat'         => time(),
        'exp'         => time() + JWT_EXPIRY,
    ), JWT_SECRET);
```

---

## After fixing — test sequence

```
1. POST /api/ess/login  { mobile_number, pin }
   → should return 200 with token + employee data

2. Use returned token in Authorization: Bearer <token>
   GET /api/ess/access
   → should return 200, not 401

3. Try wrong PIN
   → should return 401 "Invalid mobile number or PIN"

4. Try 5 wrong PINs in a row
   → should return 429 with lockout message
```

If step 1 still returns 500 after these fixes, check the server PHP
error log — the `error_log()` in the catch block will show the exact
line and message.
