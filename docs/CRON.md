# Cron Jobs

All background work runs from one script: `cron/run.php`. Add **one** cron entry that runs every minute; the runner decides which tasks are due.

```
* * * * * /usr/local/bin/php /home/CPANELUSER/public_html/ClassNex/cron/run.php all >/dev/null 2>&1
```

| Task | Runs at most every | What it does |
|---|---|---|
| `queue` | 1 min | Sends pending WhatsApp / push / email / SMS messages whose time has come (scheduled messages wait in the queue until then); retries failures with backoff (2, 4, 8, 16 … min, max 6 h, 5 attempts); honours the WhatsApp per-minute rate limit and paused channels |
| `announcements` | 1 min | Publishes scheduled announcements |
| `reminders` | 30 min | (WhatsApp **API** mode packages with *Automatic Reminders*) Sends fee reminders scheduled for today (7/3/1 days before, on due date, 3/7 days after by default). Skips paid/cancelled invoices, inactive students, expired academies and plans without automatic reminders. Reminders older than 2 days are skipped (no spam after downtime). |
| `invoices` | 60 min | Creates the next period's fee invoices for recurring fees (a few days early, *Create dues N days early* in Global Settings), applies advance credit, adds one-time late fees |
| `subscriptions` | 60 min | Moves expired plans to *grace* then *expired* (read-only, data kept); sends renewal reminders 7, 3, 1 and 0 days before expiry |
| `maintenance` | 12 h | Cleans rate-limit rows, old tokens and logs; deletes documents past their retention date |
| `backup` | 24 h | Writes a compressed database backup to `storage/private/backups` (keeps 14) |

## Safety guarantees

- **No overlap** – each task takes a MySQL named lock (`GET_LOCK`); a second copy skips while the first is running.
- **Idempotent** – invoices have a unique `(assignment, period)` key, reminders a unique `(invoice, offset)` key and are claimed atomically, queue rows are claimed with `UPDATE … locked_by`, every notification has a unique idempotency key. Running a task twice never duplicates anything.
- **Logged** – every run is recorded in `cron_runs` (visible in *Super Admin → System Health*); errors go to `storage/logs/php-error.log`.
- **Crash-safe** – queue rows stuck in *processing* for 10 minutes are released and retried.

## Running tasks manually

```bash
php cron/run.php queue            # one task (ignores the frequency check with --force)
php cron/run.php invoices --force
php cron/run.php all --force
```

## Hosts without CLI cron

Set `cron_http_key` in `config/config.php` and use a URL cron (cPanel or an external service):

```
wget -q -O /dev/null "https://your-domain/cron/run.php?key=YOUR_SECRET&task=all"
```

(`cron/` is blocked by `.htaccess`; to use the HTTP mode remove `cron` from the block list in the root `.htaccess` — the secret key protects it.)

## Shortcuts and PHP path

`cron/queue.php`, `cron/reminders.php` and `cron/subscriptions.php` run a single task (same code as `run.php <task>`), for hosts
that limit how long one cron job may run. One `run.php all` line every minute is still the recommended setup.

The PHP binary path depends on the server. On cPanel it is normally `/usr/local/bin/php`; per-domain PHP versions use
`/opt/cpanel/ea-php82/root/usr/bin/php` (8.2) or `/opt/cpanel/ea-php83/root/usr/bin/php` (8.3). Check with
`which php && php -v` in cPanel → Terminal before saving the cron job. Manual-mode WhatsApp (click-to-send) needs no cron.
