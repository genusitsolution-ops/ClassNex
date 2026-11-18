# WhatsApp Integration Guide

ClassNex uses only official WhatsApp methods: **click-to-chat links** (`https://wa.me/…`) opened by the user, and the
**WhatsApp Business Cloud API** from Meta. No unofficial automation, browser bots or unofficial gateways are used.

## The three package modes

The ClassNex Super Admin chooses the mode per package (*Super Admin → Packages → WhatsApp mode*). Every academy on
that package gets it automatically, on web and Android.

| Mode | What the academy gets | Sending | Cost to you |
|---|---|---|---|
| **Disabled** (`off`) | No WhatsApp buttons or messages. | – | none |
| **Manual click-to-send** (`manual`) | Fee reminders, payment receipts, attendance messages, admission confirmation, announcements, custom messages and *bulk reminder preparation*. | ClassNex prepares each message; tapping **Send** opens WhatsApp / WhatsApp Business with the text ready; the user presses send. | none (the academy's own WhatsApp) |
| **Official API** (`api`) | Everything automatic: fee reminders (needs the *Automatic Reminders* feature), receipts, attendance, admissions, announcements, scheduled messages. Click-to-send is also available. | Background queue + cron, Meta Cloud API, delivery webhooks, retries, duplicate prevention. | Meta conversation charges |

Earlier package values were mapped by migration 004 without changing behaviour: `full` → `api`; `transactional` → `api`
with the *Automatic Reminders* feature switched off (so those packages still send receipts/attendance automatically
but not automatic fee reminders). The seeded *Starter* package of a fresh install uses `manual`.

## Manual click-to-send (no setup needed)

Where it appears (web and Android):
- **Pending Fees → Remind all overdue**: one prepared reminder per family with that family's amount (bulk preparation).
- **Attendance**: after saving, “Prepare WhatsApp messages” for absent / late students.
- **Student profile → WhatsApp**: admission confirmation, fee reminder or a custom message.
- **Payment receipt → WhatsApp**: receipt link.
- **Announcements**: after posting, prepare the announcement for WhatsApp.
- **Reminders & Messages**: choose *WhatsApp – click to send* as a channel.

History: *WhatsApp History* (menu) lists every prepared message (type, recipient, branch, text, who prepared it) and
whether **Send** was tapped (“Opened”). ClassNex cannot see whether the user finally pressed send inside WhatsApp —
that is a limit of click-to-chat, so the history says *opened*, not *delivered*.

## Official WhatsApp Business Cloud API setup

1. **Meta Business**: business.facebook.com → create / verify the business.
2. **App**: developers.facebook.com → *Create app* → type *Business* → add the **WhatsApp** product.
3. **Phone number**: WhatsApp → *API Setup* → add and verify the business number (it must not be used in the WhatsApp app).
   Note the **Phone Number ID** and **WhatsApp Business Account ID**.
4. **Permanent token**: Business Settings → *Users → System users* → add a system user (Admin) → *Add assets*: the app
   and the WhatsApp account → *Generate token* with `whatsapp_business_messaging` and `whatsapp_business_management`.
5. **App secret**: App → *Settings → Basic* → App Secret (used to verify webhook signatures).
6. In ClassNex: *Super Admin → Integrations → WhatsApp*: paste Phone Number ID, Business Account ID, token, app secret;
   copy the **verify token** shown there.
7. **Webhook**: App → WhatsApp → *Configuration* → Callback URL
   `https://genusitsolution.com/ClassNex/api/v1/webhooks/whatsapp`, Verify token = the one from step 6 → *Verify and save*
   → subscribe to **messages**.
8. **Templates** (WhatsApp Manager → *Message templates*, category **Utility**, language `en`):

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

9. *Send test* (uses Meta's pre-approved `hello_world`), then set the mode to **Live**. *Test* mode runs the whole
   pipeline and logs messages without sending.

## How API sending works

`NotificationService` decides recipients (parents for minors, the student for adults), checks the package mode and
features, the academy's notification settings, opt-outs (STOP) and the monthly WhatsApp limit, renders the template and
writes one row per recipient to `notification_queue` with a unique idempotency key (the same event never queues twice).
The `queue` cron task claims rows atomically, sends through the Cloud API, retries temporary failures with back-off
(2, 4, 8 … minutes, up to 5 attempts), and Meta's webhook updates each row to *sent / delivered / read / failed*.
Scheduled messages (*Reminders & Messages → Schedule*) wait in the queue until their time.
*Message Log* shows every row; failed rows can be retried.

## Credentials still needed for live API sending

Meta Business verification, a WhatsApp business number, the permanent token, app secret and approved templates.
Without them the API mode works in *Test* mode only. Manual mode needs nothing.
