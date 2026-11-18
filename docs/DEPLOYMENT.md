# Deployment Guide – https://genusitsolution.com/ClassNex/

ClassNex is plain PHP + MySQL. It needs no Composer, Node, Redis or background daemon, and it is built to run
**inside a sub-folder** (`/ClassNex/`), not only at the domain root. This was verified by installing a copy
under Apache at `http://127.0.0.1:8090/ClassNex/` through the web installer and running the full API suites
against that address (see [QA_REPORT.md](QA_REPORT.md)).

> Commands below use `CPANELUSER` for your cPanel user name. Replace it everywhere.

## 1. PHP version

cPanel → **Select PHP Version** (or *MultiPHP Manager*) → choose **PHP 8.2 or 8.3** for `genusitsolution.com`.

## 2. Required PHP extensions

In *Select PHP Version → Extensions* tick: `pdo_mysql`, `sodium` (recommended; if your host does not offer it, ClassNex uses OpenSSL AES-256-GCM instead), `gd`, `curl`, `mbstring`, `openssl`, `zip`, `fileinfo`
(recommended `exif`, `intl`). In *Options* set `upload_max_filesize = 10M`, `post_max_size = 12M`,
`memory_limit = 256M`, `max_execution_time = 60`. The installer checks all of these and shows ✅ / ❌.

## 3. MySQL database

cPanel → **MySQL Databases**:
1. Create database, e.g. `CPANELUSER_classnex`.
2. Create user, e.g. `CPANELUSER_cnx`, with a long random password.
3. *Add user to database* → **ALL PRIVILEGES**.

## 4. Upload the files and database import

1. Unzip **ClassNex-Web-Complete.zip** on your computer.
2. cPanel → **File Manager** → `public_html` → create folder **`ClassNex`** → upload the ZIP into it → *Extract*.
   You should now have `public_html/ClassNex/index.php`, `public_html/ClassNex/.htaccess`, `…/api/`, `…/class-admin/` etc.
3. Database — choose **one**:
   - **Web installer (recommended):** go to step 5; it creates all tables itself.
   - **phpMyAdmin:** select the empty database → *Import* → `database/install.sql`; then create the Super Admin
     with SSH/Terminal (`php cli/create-superadmin.php …`, step 5b) and create `config/config.php` by hand (step 5c).

## 5. Config file setup (installer)

Open **https://genusitsolution.com/ClassNex/install.php**:
1. All requirement rows must show ✅.
2. Database: host `localhost`, port `3306`, the database name, user and password from step 3.
3. Website address: **`https://genusitsolution.com/ClassNex`** (no trailing slash).
4. Super Admin: name, mobile, email (login) and a strong password.
5. *Install*. The installer writes `config/config.php` (with a fresh encryption key), runs every migration,
   creates the Super Admin and writes `storage/installed.lock`. After that it refuses to run again (HTTP 403).
6. Delete `install.php` from the server (recommended).

5b. Super Admin from Terminal (only if you imported `install.sql`):
```bash
cd ~/public_html/ClassNex
php cli/create-superadmin.php "Your Name" admin@genusitsolution.com 9XXXXXXXXX 'Strong#Pass123'
```
5c. Manual config (only if you did not use the installer): copy `config/config.sample.php` to `config/config.php`
and set `app_url = 'https://genusitsolution.com/ClassNex'`, the `db` block, `env = 'production'`, `debug = false`
and a new `app_key` (`php -r "echo base64_encode(random_bytes(32));"`).

**Back up `config/config.php` immediately.** Its `app_key` decrypts stored integration credentials and student documents.

## 6. Folder permissions

Directories `755`, files `644`. PHP must be able to write to `ClassNex/storage/`, `ClassNex/uploads/` and
(during installation only) `ClassNex/config/`. After installing you can set `config/config.php` to `640`.

## 7. Upload directories

Nothing to create by hand: `uploads/` holds public images (academy logos, website images) and only image
files can be served from it (`uploads/.htaccess`). Student photos, documents, backups and logs go to
`storage/private/`, which is never reachable from the web. For extra safety you may move `storage/` outside
`public_html` and set `storage_path` in `config/config.php`.

## 8. .htaccess

`ClassNex/.htaccess` is included and works inside the sub-folder (all rules are relative). It:
- routes `/ClassNex/api/v1/...` to the API and passes the `Authorization` header (Android bearer tokens),
- blocks `config`, `classes`, `services`, `includes`, `database`, `storage`, `cli`, `cron`, `tests`, `docs`, `android`
  and `*.sql|log|md|json|…` files (verified: all return 403),
- sets security headers and caching for static files.

If your host runs PHP-FPM, `php_value` lines inside `<IfModule mod_php.c>` are ignored automatically; set the
same limits in *Select PHP Version → Options*.

## 9. SSL

cPanel → **SSL/TLS Status** → *Run AutoSSL*. Then in `ClassNex/.htaccess` un-comment the *Force HTTPS* lines and the
`Strict-Transport-Security` header. `app_url` must start with `https://`.

## 10. API URL

The API lives at **`https://genusitsolution.com/ClassNex/api/v1/`**. Quick check in a browser:
`https://genusitsolution.com/ClassNex/api/v1/public/packages` returns JSON with your packages.

## 11. Android API configuration

The app already points to `https://genusitsolution.com/ClassNex/api/v1/` (one setting: `classnexApiUrl` in
`android/gradle.properties`; CI uses the repository variable `CLASSNEX_API_URL` if you set one). See [ANDROID.md](ANDROID.md).

## 12. Cron job (required)

cPanel → **Cron Jobs** → *Common settings: Once Per Minute* (`* * * * *`) → command:
```
/usr/local/bin/php /home/CPANELUSER/public_html/ClassNex/cron/run.php all >/dev/null 2>&1
```
`/usr/local/bin/php` is the standard cPanel PHP command-line binary. **Verify it on your server** (I could not
access the server): cPanel → *Terminal* → `which php` and `php -v` (must show 8.2+). If your PHP version is
selected per domain, use the version-specific binary instead, e.g. `/opt/cpanel/ea-php82/root/usr/bin/php`.
After 5 minutes, *Super Admin → System Health* must show recent runs. Details: [CRON.md](CRON.md).

## 13. Firebase (push notifications) → [FIREBASE.md](FIREBASE.md)
## 14. WhatsApp → [WHATSAPP.md](WHATSAPP.md)

## 15. SMTP (email)

*Super Admin → Integrations → Email*: host (e.g. `mail.genusitsolution.com`), port `465` (SSL) or `587` (TLS),
mailbox user and password, sender name/email. *Send test*, then switch the mode to **Live**.

## 16. Razorpay

- **Platform keys** (*Super Admin → Integrations → Razorpay*): Class Admins pay their ClassNex subscription online.
  Webhook: `https://genusitsolution.com/ClassNex/api/v1/webhooks/razorpay/platform`.
- **Academy keys** (*Class Admin → Settings → Online payments*): parents pay fees to the academy. The webhook URL is shown on that screen.
- Events: `payment.captured`, `order.paid`. Use test keys first, then live keys.

## 17. Backups → [BACKUP_RESTORE.md](BACKUP_RESTORE.md)

Daily automatic database backups are made by the cron job; also enable cPanel backups and keep off-site copies.

## 18. Production verification checklist

- [ ] `https://genusitsolution.com/ClassNex/` opens the website; *Login* works for the Super Admin.
- [ ] `https://genusitsolution.com/ClassNex/config/config.php` → **403**; same for `/storage/`, `/database/install.sql`.
- [ ] `config/config.php`: `env = production`, `debug = false`, https `app_url`, `app_key` backed up.
- [ ] HTTPS forced; `install.php` deleted.
- [ ] Cron shows recent runs in *System Health*.
- [ ] Packages reviewed (*Super Admin → Packages*), WhatsApp mode chosen per package.
- [ ] Register a test academy on the website → it appears in *Registration Requests* → approve → log in as Class Admin.
- [ ] Add a branch, a batch and a student; mark attendance; collect a fee; open the receipt.
- [ ] Integrations tested in *Test* mode, then switched to *Live*.
- [ ] Android app installed from the release build, logs in against the live API.

## Upgrading to a future version

1. Back up the database and `config/config.php`, `storage/`, `uploads/`.
2. Upload the new files **over** the old ones (never overwrite `config/config.php`, `storage/`, `uploads/`).
3. Run `php cli/migrate.php` (Terminal) — only new migrations are applied; existing data is kept.
4. Check *System Health*.
