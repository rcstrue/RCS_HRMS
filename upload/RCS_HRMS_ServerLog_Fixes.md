# Server Error Fixes — 2 Bugs from Live Logs

Two distinct errors from the LiteSpeed error log. Fix in order.

---

## Error 1 — `Undefined constant "JWT_EXPIRY"` → login 500

**Root cause:**
`JWT_EXPIRY` is defined in `example.config.php` (the template in the repo),
but the live server's real `api/ess/config.php` was created from an older
version of that template and is missing the `JWT_EXPIRY` line. The constant
exists in the repo file but not on the server.

**Fix — on the live server, edit `/home/rcsfaxhz/domains/join.rcsfacility.com/public_html/api/ess/config.php`:**

FIND:
```php
define('JWT_SECRET', 'your_actual_jwt_secret');
```
ADD this line immediately after it:
```php
define('JWT_EXPIRY', 3600); // 1 hour token lifetime
```

> This is a server-only edit — not a repo change. The value `3600`
> matches `example.config.php`. If you previously used a different
> expiry (e.g. 86400 for 24h), use that value instead.

---

## Error 2 — `Unknown column 'u.name'` → announcements crash

**Root cause:**
The `users` table has columns `first_name` and `last_name` (confirmed from
`audit/list.php` and `profile/settings.php` in the codebase) — it does NOT
have a `name` column. Two files use `u.name` in a `COALESCE()` — both crash.

**File 1:** `php_payroll/templates/header.php`

FIND:
```php
SELECT a.*, COALESCE(e.full_name, u.name, a.created_by) AS creator_name
```
REPLACE:
```php
SELECT a.*, COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name), a.created_by) AS creator_name
```

**File 2:** `php_payroll/modules/notifications/announcements.php`

FIND:
```php
               COALESCE(e.full_name, u.name, a.created_by) AS creator_name
```
REPLACE:
```php
               COALESCE(e.full_name, CONCAT(u.first_name, ' ', u.last_name), a.created_by) AS creator_name
```

---

## Deploy checklist

```
□ Edit live config.php on server — add JWT_EXPIRY line (Error 1)
□ Commit + push header.php fix (Error 2, File 1)
□ Commit + push announcements.php fix (Error 2, File 2)
□ Deploy to server
□ Test: POST /api/ess/login → should now return 200 with token
□ Test: open HRMS admin dashboard → announcements panel should load
□ Check error log for 5 minutes after deploy — both error lines should be gone
```

---

## After login works — verify token flow

```
1. Login → copy token from response
2. GET /api/ess/access
   Header: Authorization: Bearer <token>
   → expect 200, not 401
3. GET /api/ess/employees
   → expect 200 with employee data
```
If step 2 returns 401 even after a successful login, the `JWT_EXPIRY`
value in `config.php` may still be missing and `SimpleJWT::encode()`
is throwing a silent error inside token generation.
