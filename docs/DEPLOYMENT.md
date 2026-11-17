# Deployment Guide (cPanel / PHP–MySQL hosting)

ClassNex runs on ordinary PHP/MySQL hosting. No Composer, Node, Redis or background daemons are required.

## 1. Server requirements

| Item | Requirement |
|---|---|
| PHP | 8.2 or newer (8.3 recommended) |
| PHP extensions | `pdo_mysql`, `sodium`, `gd`, `curl`, `mbstring`, `openssl`, `zip`, `fileinfo` (recommended: `exif`, `intl`) |
| Database | MySQL 8.0+ (MariaDB 10.6+ also tested) |
| Web server | Apache with `mod_rewrite` and `.htaccess` enabled (LiteSpeed works the same) |
| PHP limits | `upload_max_filesize ≥ 10M`, `post_max_size ≥ 12M`, `memory_limit ≥ 256M`, `max_execution_time ≥ 60` |
| Cron | One cron entry running every minute |
| SSL | Required in production (free AutoSSL / Let's Encrypt in cPanel) |

cPanel: **Select PHP Version** → choose 8.2/8.3 → tick the extensions above → **Options** to set the limits.

## 2. Upload the files

1. Download the repository ZIP from GitHub (or `git clone` over SSH).
2. Upload the *contents* into `public_html` (or a sub-domain's document root). The `android/`, `tests/` and `docs/` folders are not needed on the server and may be omitted.
3. Folder permissions: directories `755`, files `644`. `storage/`, `uploads/` and `config/` must be writable by PHP (`755` is enough on suPHP/LSAPI; use `775` if your host runs PHP as a different user).

Private folders (`config`, `classes`, `services`, `storage`, `database`, `cli`, `cron` …) are blocked by `.htaccess` files. For maximum safety you can move `storage/` outside `public_html` and set `storage_path` in `config/config.php`.

## 3. Database

cPanel → **MySQL Databases**: create a database (e.g. `user_classnex`) and a user with a strong password, and add the user to the database with **ALL PRIVILEGES**.

## 4. Install

Open `https://your-domain/install.php`:

1. The wizard checks PHP version, extensions and folder permissions.
2. Enter database details, the site address (`https://www.your-domain.com`) and the Super Admin account.
3. It writes `config/config.php` (with a freshly generated encryption key), creates all tables and the Super Admin.
4. Delete `install.php` afterwards (it refuses to run again anyway).

**Back up `config/config.php`.** The `app_key` in it encrypts integration credentials and student documents — without it encrypted documents cannot be read.

Command-line alternative (SSH):

```bash
cp config/config.sample.php config/config.php     # edit db, app_url, app_key
php -r "echo base64_encode(random_bytes(32)), PHP_EOL;"   # → app_key
php cli/migrate.php
php cli/create-superadmin.php "Admin Name" admin@your-domain.com 9876543210 'StrongPass123'
```

Upgrading later: upload the new files (keep `config/`, `storage/`, `uploads/`), then run `php cli/migrate.php` (or re-open any page — new migrations are applied by the CLI only; use cPanel *Terminal* or a one-off cron).

## 5. SSL and HTTPS

1. cPanel → **SSL/TLS Status** → *Run AutoSSL* (or install your certificate).
2. In `.htaccess` uncomment the *Force HTTPS* block and the `Strict-Transport-Security` header.
3. Make sure `app_url` in `config/config.php` starts with `https://`.

## 6. Cron (required)

cPanel → **Cron Jobs** → *Once Per Minute* (`* * * * *`):

```
/usr/local/bin/php /home/CPANELUSER/public_html/cron/run.php all >/dev/null 2>&1
```

Use the PHP path shown by your host (`which php` in Terminal; on some hosts `/opt/cpanel/ea-php83/root/usr/bin/php`). Details in [CRON.md](CRON.md). Check *Super Admin → System Health* after a few minutes: every task should show a recent run.

## 7. Integrations

Configure in **Super Admin → Integrations**. Each channel has **Off / Test / Live** modes; *Test* runs the whole pipeline and logs messages without sending.

### WhatsApp Business Cloud API (official)
1. Create a Meta Business account and an app at developers.facebook.com → add the **WhatsApp** product.
2. Add and verify your business phone number. Note **Phone Number ID** and **WhatsApp Business Account ID**.
3. Create a **System User** (Business Settings → Users → System users), give it the app and the WhatsApp account, and generate a **permanent access token** with `whatsapp_business_messaging` and `whatsapp_business_management`.
4. App → Settings → Basic → copy the **App Secret** (used to verify webhook signatures).
5. Webhook: callback URL `https://your-domain/api/v1/webhooks/whatsapp`, verify token = the one shown in ClassNex; subscribe to the `messages` field.
6. In WhatsApp Manager create these **Utility** templates (language `en`) and enter the names in ClassNex:

| Template name | Body (variables in this order) |
|---|---|
| `classnex_fee_due` | Dear Parent, the {{1}} of {{2}} for {{3}} is due on {{4}}. Please complete the payment. Thank you – {{5}}. |
| `classnex_fee_overdue` | Dear Parent, the {{1}} of {{2}} for {{3}} was due on {{4}} and is still pending. Please pay at the earliest. – {{5}} |
| `classnex_payment` | Payment of {{1}} received for {{2}}. Receipt No: {{3}}. Balance: {{4}}. Download: {{5}} – {{6}} |
| `classnex_attendance` | {{1}} was marked {{2}} on {{3}} at {{4}}. |
| `classnex_admission` | Welcome {{1}}! Admission to {{2}} at {{3}} is confirmed. Student ID: {{4}}. |
| `classnex_general` | {{1}} |
| `classnex_invite` | {{1}} has invited you to ClassNex. Activate your account: {{2}} |
| `classnex_password_reset` | Reset your ClassNex password: {{1}} (valid for 1 hour). |
| `classnex_subscription` | Your ClassNex {{1}} plan for {{2}} expires on {{3}}. Please renew from your dashboard. |

7. Use **Send test** (sends Meta's pre-approved `hello_world`), then switch to **Live**.

Recipients who reply **STOP** are opted out automatically (START re-subscribes). Meta conversation charges apply per your Meta billing.

### Firebase push notifications
1. console.firebase.google.com → create project → add an **Android app** with package `in.classnex.app` (and `in.classnex.app.debug` for debug builds).
2. Download `google-services.json` → store it as the GitHub secret `GOOGLE_SERVICES_JSON` (see [ANDROID.md](ANDROID.md)).
3. Project settings → Service accounts → **Generate new private key** → paste the JSON in ClassNex → Firebase.

### Email (SMTP)
Any SMTP mailbox: cPanel email account (`mail.your-domain.com`, port 465 SSL or 587 TLS), Google Workspace (App Password), Zoho, Amazon SES. Send a test email from the integration screen.

### SMS (optional)
Works with any HTTP SMS gateway — fill in endpoint, method and the parameter template. In India the text must match a DLT-registered template.

### Razorpay
- *Platform keys* (Super Admin → Integrations → Razorpay): owners pay their ClassNex subscription online. Webhook `…/api/v1/webhooks/razorpay/platform`.
- *Academy keys* (Owner → Settings → Online payments): parents pay fees directly to the academy. Webhook URL is shown on that screen.
Events: `payment.captured`, `order.paid`. Payments are recorded only after server-side signature verification.

### OCR
Default: on-device (Android ML Kit, browser Tesseract.js) — free and private. Optional: Google Cloud Vision API key for server-side OCR.

## 8. Backups and restore

- The cron task `backup` writes a compressed SQL dump daily to `storage/private/backups/` (14 kept). *Super Admin → System Health* can create and download backups on demand.
- Also enable cPanel's own backups / JetBackup, and keep off-site copies of `storage/private/` (documents, photos) and `config/config.php`.
- Restore (SSH): `php cli/backup.php restore storage/private/backups/classnex-YYYYMMDD-HHMMSS.sql.gz` (type `YES` to confirm). Or import the `.sql.gz` with phpMyAdmin.

## 9. Production checklist

- [ ] `config/config.php`: `env = production`, `debug = false`, `https://` app_url, unique `app_key` (backed up)
- [ ] HTTPS forced and HSTS enabled in `.htaccess`
- [ ] `install.php` deleted
- [ ] Cron running every minute (System Health shows recent runs)
- [ ] Integrations tested, then switched from *Test* to *Live*
- [ ] Super Admin password strong and unique; support email set in Global Settings
- [ ] Website content, pricing and legal pages reviewed in Website CMS
- [ ] Off-site backups configured
- [ ] Android app built with the production API URL ([ANDROID.md](ANDROID.md))
