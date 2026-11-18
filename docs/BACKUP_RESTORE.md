# Backup and Restore Guide

## What to back up

| Item | Where | Why |
|---|---|---|
| Database | MySQL | all academies, students, attendance, fees |
| `config/config.php` | `ClassNex/config/` | database login and the **app_key** that decrypts documents and integration credentials |
| Private files | `ClassNex/storage/private/` | student photos, documents (encrypted), backups |
| Public uploads | `ClassNex/uploads/` | logos and website images |

## Automatic database backups

The cron task `backup` writes a compressed dump once a day to `storage/private/backups/classnex-YYYYMMDD-HHMMSS.sql.gz`
and keeps the latest 14. *Super Admin → System Health* can also create a backup on demand and download any backup.
These files are inside `storage/private`, which is never reachable from the web.

Also switch on cPanel's own backups (or JetBackup) and copy the latest backup, `config/config.php` and
`storage/private/` off the server regularly (e.g. weekly to Google Drive).

## Manual backup

- Terminal: `cd ~/public_html/ClassNex && php cli/backup.php` → prints the file name.
- phpMyAdmin: select the database → *Export* → *Quick*, SQL.

## Restore

**A restore replaces the current data. Take a fresh backup first.**

- Terminal (recommended):
  ```bash
  cd ~/public_html/ClassNex
  php cli/backup.php restore storage/private/backups/classnex-20261118-020000.sql.gz
  # type YES to confirm
  ```
- phpMyAdmin: create/empty the database → *Import* → choose the `.sql.gz` file.
- Restore `config/config.php` (same `app_key`!) and `storage/private/` from your copies if the server was lost.

After restoring onto a newer ClassNex version, run `php cli/migrate.php` to apply any newer migrations.

## Tested

The automated suite (`tests/e2e.php`, also in GitHub Actions on MySQL 8) creates a backup with `cli/backup.php`,
restores it into a new empty database and checks that the restored payment count matches the original.
