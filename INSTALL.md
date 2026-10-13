# SmartBils — Install & Deploy

## 1. Requirements

| | |
|---|---|
| PHP | 8.2+ |
| MySQL / MariaDB | 10.4+ (InnoDB, utf8mb4) |
| PHP extensions | `pdo_mysql`, `mbstring`, `openssl`, `fileinfo`, `gd` |
| Web server | Apache with `mod_rewrite` + `AllowOverride All` (XAMPP works) |
| HTTPS | Required in production (session cookie `Secure` + HSTS auto-enable) |

Enable `gd` in `php.ini` (`extension=gd`) and restart Apache.

## 2. Fresh install (local XAMPP)

1. Clone / drop the folder into `C:\xampp\htdocs\smartbilling`.
2. In phpMyAdmin, create database **`smartbilling`** (utf8mb4).
3. Import **`database/install.sql`** (combined; runs every phase in order).
4. Visit `http://localhost/smartbilling/setup.php` → create first Super Admin + optional demo shop → **delete `setup.php`** when it prompts.
5. (Only if you did **not** use `setup.php`) the migration auto-seeds `admin / admin@123` — change the password immediately via avatar → **Change password**.

## 3. Config

| File | What to set |
|---|---|
| `config/app.php` | `APP_ENV` → `production`, verify `BASE_URL`, `APP_TIMEZONE`, `APP_CURRENCY` |
| `config/database.php` | `DB_HOST`, `DB_NAME`, `DB_USER`, `DB_PASS` — do **not** commit the real file |

In production:
- `APP_ENV = 'production'` disables error display, logs to `storage/logs/php-error.log`
- All responses get `X-Content-Type-Options`, `X-Frame-Options: SAMEORIGIN`, `Referrer-Policy`, `Permissions-Policy`
- HTTPS adds `Strict-Transport-Security`

## 4. File-system layout and permissions

```
smartbilling/
├── admin/      client-managed by Super Admin
├── client/     shop workspace
├── ajax/       JSON endpoints (shop-scoped, CSRF-protected)
├── receipts/   printable templates (A4 / 80mm / 58mm)
├── assets/     css + js (served)
├── config/     app + db config          ← .htaccess denies HTTP
├── includes/   auth, helpers, layouts   ← .htaccess denies HTTP
├── database/   schema + migrations      ← .htaccess denies HTTP
├── storage/    logs, backups, exports   ← .htaccess denies HTTP, write-permission required
├── uploads/    user uploads             ← .htaccess denies PHP execution, write-permission required
```

On Linux prod: `chown -R www-data:www-data storage uploads` + `chmod -R 775 storage uploads`.

## 5. Email settings (optional)

Admin → avatar → **Email settings** → paste Gmail name + sending email + App password.
If left blank, new-shop welcome emails are **not** sent — flash tells admin to share credentials manually.
Pure-PHP Gmail SMTP over STARTTLS, no PHPMailer dependency.

## 6. First login

- Shop login: `/` (root) → `admin / admin@123` won't work here (that's admin-only)
- Super Admin login: `/admin/` → `admin / admin@123` (change immediately)

Create shops from **Admin → Shops → Add Shop**; the shop owner gets credentials via email (if configured) or shown in the admin flash.

## 7. Hardening checklist for production

- [ ] `APP_ENV = 'production'` in `config/app.php`
- [ ] `config/database.php` contains real creds, is **outside git**
- [ ] `setup.php` **deleted** (or kept locked by `storage/installed.lock`)
- [ ] `db-test.php` **deleted** (only used during Phase 1 setup)
- [ ] HTTPS enforced at the web-server level
- [ ] Default admin password **changed**
- [ ] Default plan prices updated (`admin/plans/`) or deleted
- [ ] Scheduled job runs **Admin → Backups → Save on server** daily (or `wget https://.../admin/backups/?do=download` with a session cookie — tie to cron)
- [ ] `storage/backups/` restore tested at least once (see `TESTING.md` → Backup/Restore)

## 8. Backup

- One-click: **Admin → Backups → Download .sql** (streams full dump)
- Server-saved: **Save on server** → file lands in `storage/backups/` (behind `.htaccess`)
- Restore: upload the .sql + type `RESTORE` to confirm → statements executed one by one, audited

## 9. Updating in-place

For future updates, drop new SQL files into `database/migrations/` and run them in phpMyAdmin.
No ORM — plain `ALTER TABLE` keeps upgrades predictable.
