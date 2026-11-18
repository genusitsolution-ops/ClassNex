# Super Admin Setup

After installation, log in at `/login.php` with the Super Admin email and password.

## Day-one checklist

1. **Global Settings** – platform name, support email/phone, grace days after expiry (default 7), default trial package, whether owners can self-register.
2. **Packages** – review Starter (₹100 / 15 students), Growth (₹200 / 100 students), Professional and Enterprise (examples): name, price, billing cycle (monthly … yearly, lifetime or **custom number of days**), trial days, **maximum active students** (counted once per student across *all* branches of the academy), optional teacher/batch/storage/WhatsApp limits, **WhatsApp mode** (off / transactional / full) and features. Branches are always unlimited and never need a separate subscription. A per-owner custom student limit can be set when assigning a package. Changes apply instantly to every subscriber — the backend enforces them on web and Android.
3. **Integrations** – WhatsApp Cloud API, Firebase, SMTP, SMS (optional), Razorpay (subscription payments), OCR. Start in *Test* mode, send a test, then switch to *Live*. See [DEPLOYMENT.md §7](DEPLOYMENT.md#7-integrations).
4. **Message Templates** – default texts for each event and channel; enter your Meta-approved WhatsApp template names.
5. **Reminder Rules** – default fee reminder days (academies can override).
6. **Website CMS** – hero text/image, feature cards, categories, steps, numbers, FAQ, testimonials, about, contact, social links, SEO, footer, legal pages. Pricing is generated from public packages.
7. **System Health** – confirm the cron job runs and all extensions are present; create a first backup.

## Managing owners

The owner list shows each academy's active students against its package limit and its number of branches. An academy's detail page shows combined and branch-wise students, teachers, collection and pending fees for the current month.


- **Add owner** creates the owner login, academy and subscription in one form. Leave the password blank to send an activation link by WhatsApp/email (the link is also shown to copy).
- **Academy page**: assign/change package, start a trial, renew (optionally recording the payment), extend by N days, suspend/restore subscription, custom per-owner limits, record offline subscription payments, edit owner/academy, send a login or password-reset link, suspend the whole academy.
- Expired academies stay readable (read-only) — data is never deleted because a plan lapsed.

## Monitoring

- **Dashboard** – academies, students, active/trial/expired plans, revenue, expiring plans, notification volumes, last cron run.
- **Notification Queue** – every message with status; retry/cancel selected, pause/resume a channel (e.g. while fixing WhatsApp credentials), view the provider request/response log.
- **Reports** – subscriptions, subscription payments, academies, notification usage (PDF/Excel/CSV).
- **Audit Log** – every important action across the platform.
- **Enquiries** and **Support Tickets** from the website and owners.
