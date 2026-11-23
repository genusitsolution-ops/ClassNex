# ClassNex Android App

One native app (`in.classnex.app`) for every role. After login the app shows the menu, start screen and actions for the signed-in user's role, package features and permissions — the same rules as the web panel, because both use the same REST API.

## Version 1.0.22 (23 Nov 2026)

Current version: **1.0.22, versionCode 22**, package `in.classnex.app` (unchanged). Light top bars, animated bottom-nav pill, hero Home with tile grid and charts, filterable Students, status-pill Attendance, tabbed Fees, grouped More menu, plus new Class Admin screens (Receipts & IDs, Online admission, Online payments, Activity log, refund, cancel fee, unlink parent, enrol in another activity). Details: `RELEASE_1.0.22.md`, `FEATURE_COMPARISON_1.0.22.md`, `QA_1.0.22.md`.

## Redesign (November 2026)

The whole app was redesigned to the ClassNex concept: white / lavender background, deep-indigo gradient app bar, purple → indigo gradient buttons, rounded white cards with soft shadows, tinted circular icons, Plus Jakarta Sans typeface (same font as the website).

| Area | What the user gets |
|---|---|
| Navigation | Sectioned **drawer** per role (Overview · Academy Management · Daily Operations · Finance · Communication · Business & Account) showing only modules enabled in the package and allowed by permissions; **bottom bar** (Home · Students · Attendance · Fees · More) with an animated purple pill; gradient top bar with back / menu and notifications |
| Splash / onboarding / login | Animated logo splash, 3-page onboarding, Welcome Back login, **Register Your Academy** (full page) and **Track Registration Status** (mobile/email + password → waiting / approved / not approved with reason) |
| Dashboard (Class Admin) | Greeting, academy name, **branch selector** (all figures for one branch; student quota stays academy-wide), totals (students, teachers, branches, activities, batches, pending admissions), today's attendance **donut** (present / late / absent / leave), fee collection this month with change vs last month and 6-month **bar chart**, pending / overdue, quick actions, upcoming dues, scheduled reminders, recent payments, notifications – all from `GET /dashboard` |
| Dashboard (Teacher) | Today's classes with "mark now", my students, my batches |
| Attendance | **Date (today by default) → Branch → Activity → Batch**, register opens with everyone **Present**; tap P / A / L / Lv only for exceptions; All Present / All Absent; live counts; nothing saved until **Save**; an existing register loads for editing (saved as corrections with optional reason); success animation; optional click-to-send WhatsApp to parents (manual mode) |
| Students | Search, branch chips, dues on each row, add-student button; **profile** with photo upload, Call / WhatsApp / Collect / Edit / Message, tabs **Overview · Attendance · Fees · Batches · Documents** (month donut + day list, invoices, payment history with receipt PDF, enrolments, change batch, change branch, assign fee, add charge, add parent, upload / open / delete documents – Aadhaar is never asked for) |
| Fees | Collect fee (pending / overdue / advance tiles, select invoices, partial-payment and advance hints, methods Cash · UPI · Bank Transfer · Card · Online · Cheque · Other, reference, date, note) → success animation → receipt PDF view / share / WhatsApp; Pending / Overdue tabs with summary and search, remind-all; Payments & Receipts with PDF, share, WhatsApp and **reverse** (Class Admin with permission, reason required); Fee Structure (plans, apply to students), Discounts; Financial reports. Amounts are only sent to the server – the existing allocation logic is untouched |
| Academy management | Branches (existing full module), Activities, Batches (days, timings, teachers, capacity, students list, cancel class), **Teachers** (add / edit, branch, salary type, batches, extra permissions, password / invite), Parents, Online Admissions (approve / reject), Holidays, Teacher Leaves (teacher: apply; Class Admin: approve / reject), Timetable (week view) |
| Communication | WhatsApp reminders & messages (mode banner: automatic API / click-to-send / off), Announcements, Communication centre, Message log with honest statuses (**Queued** until the provider accepts; delivered/read only when the provider reports it), WhatsApp click-to-send history. In manual mode the app shows **"Opened ✓"** – never "sent" or "delivered" |
| Business & account | Reports list (financial reports only with permission), Subscription (plan, usage bars, features, plans; renewal opens the secure web checkout), Academy Profile (details, logo, receipt texts, online admission switch, share admission link), Notification settings (event × channel matrix, automatic reminder days), Settings (profile, change password, log out), Help & Support (tickets with replies) |
| Parent / Student | Child switcher, home, attendance by month, fees & receipts, notifications, help |
| Super Admin | Platform figures and revenue chart, **registration approvals** (approve / reject with reason), academies list; full configuration stays on the web panel |

Interaction details: card entrance animations, press-scale feedback, skeleton (shimmer) loading, cross-fades between screens, animated bottom bar, expand / collapse cards, success animations after saving attendance and collecting a fee, keyboard-aware forms (`imePadding`), edge-to-edge with correct status / navigation bar insets.

## Simplification for academy owners (v1.0.19 onwards)

Design rule: **fewer decisions, fewer steps, simple words.** Nothing was removed – advanced options are folded away.

| Area | Simple by default | Still available |
|---|---|---|
| Dashboard | Six big buttons first: Add Student, Mark Attendance, Collect Fee, Pending Fees, Students, Send Reminder; then four numbers (Total Students, Today's Attendance, Collected This Month, Pending Fees); then fees due this week | Branch selector; everything else in **More** |
| Navigation | Bottom bar: Home · Students · Attendance · Fees · More. **More** shows large tiles: Branches, Teachers, Activities & Batches, Reports, Reminders & WhatsApp, Announcements, Subscription, Settings, Help & Support | "Other tools" list: Payments & Receipts, Fee Plans, Discounts, Fee Reports, Attendance History, Timetable, Admission Requests, Parents, Holidays, Leaves, Academy Profile |
| Reminders & WhatsApp | "Would you like ClassNex to remind parents about pending fees automatically?" – **Automatic Reminders ON / OFF** with ClassNex default timings; "How would you like to send WhatsApp messages?" – Manual / Automatic / Turned off in plain words (no tokens, no API details; a clear note if Automatic is not connected yet) | **Advanced settings**: own reminder days, which messages go to which channel |
| Attendance | Date → Branch → Batch, everyone Present, clear **Present / Absent / Late / Leave** buttons, compact counts, choices fold into one line | Activity filter kept internally; correction reason |
| Collect fee | Pending / Overdue / Advance, then 1 Select the fees → 2 Enter the amount → 3 How did they pay? → **Confirm** → receipt | "More options": transaction number, date, note; partial, advance, discounts, cancel payment unchanged |
| Add student | Photo (optional), name, WhatsApp, branch, activity, batch, (parent name for under-18) | "More details": date of birth, gender, email, address, document scan with auto-fill, consent and message options |
| Forms | Only the fields needed to save | "More options" in activities, batches, teachers, fee plans, student edit, assigned fees |

Same settings on the web: *Settings → Reminders & WhatsApp* shows the same two simple cards with the existing matrix and schedule under *Advanced settings*.
API (additive): `GET/POST /notification-settings` – `reminder_mode` (`default` / `custom` / `off`), `default_reminder_rules`, `whatsapp` (choices allowed by the plan) and `whatsapp_preference`. The academy can choose the same or a simpler WhatsApp mode than its plan, never a higher one.

### Code structure

| Folder (`app/src/main/java/in/classnex/app/`) | Content |
|---|---|
| `ui/theme/Theme.kt` | Colours, gradients (`Brand`), typography (Plus Jakarta Sans in `res/font`), shapes (`Radius`) |
| `ui/common/Components.kt` | Design-system components: `GradientTopBar`, `GradientButton`, `SoftButton`, `CnxCard`, `StatTile`, `IconCircle`, `Pill`, `DonutChart`, `BarChart`, `ProgressLine`, `SkeletonList`, `EmptyState`, `SuccessOverlay`, `CnxField`, `SelectField`, `DateField`, `TimeField`, `SegmentedTabs`, `ChoiceChips`, `SearchBox`, `PersonRow`, `CnxBottomBar`, `ConfirmDialog`, `Entrance`, `pressable` |
| `ui/common/Common.kt` | Formatting helpers, `LoadVm` / `Load` state, file open / share |
| `ui/crud/Crud.kt`, `Modules.kt` | Configurable list + form engine (field types: text, number, money, phone, email, date, time, select, multi-select, switch, weekdays, password) and the module definitions with their permission rules |
| `ui/nav/AppNav.kt` | Routes, drawer menu per role (`menuFor`), bottom bar, titles |
| `ui/academy/*` | Dashboard, students and profile, attendance flow, fees / payments / messages, branches, history / reports / WhatsApp, timetable, subscription, academy profile, notification settings, support |
| `ui/auth`, `ui/portal`, `ui/admin` | Login & registration, parent / student portal, super admin + notifications + settings |

API additions used by the redesign (additive, web unchanged): `GET /dashboard?branch_id=` (+ `total_branches`, late / leave counts, last month collection, scheduled reminders), `POST /public/registration-status`, `batch_ids` in `GET /teachers`.

**Stack (unchanged):** Kotlin 2.0, Jetpack Compose (Material 3), MVVM with StateFlow/SharedFlow, Hilt, Retrofit + OkHttp + kotlinx.serialization, Coroutines, Coil, ML Kit Text Recognition, Firebase Cloud Messaging, EncryptedSharedPreferences. Min SDK 24 (java.time via core-library desugaring), target SDK 35.

## Configuration

| Setting | How |
|---|---|
| Backend URL | One setting: Gradle property `classnexApiUrl` in `android/gradle.properties` = **`https://genusitsolution.com/ClassNex/api/v1/`** (must end with `/api/v1/`). It becomes `BuildConfig.API_BASE_URL`, used by Retrofit, downloads and the “open web panel” link – no URL is written in any screen. Override per build with `-PclassnexApiUrl=…`, or in GitHub with the repository **variable** `CLASSNEX_API_URL`. |
| Push notifications | Repository **secret** `GOOGLE_SERVICES_JSON` = full content of `google-services.json`. Without it the app builds and works; push is simply disabled. |
| Release signing | Secrets `CLASSNEX_KEYSTORE_BASE64`, `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS`, `CLASSNEX_KEY_PASSWORD` |

Never commit keystores, passwords or `google-services.json` (they are in `.gitignore`).

## Building locally

```bash
cd android
./gradlew testDebugUnitTest assembleDebug                         # unit + API contract tests, debug APK
./gradlew assembleRelease bundleRelease                           # release APK / AAB (signed only with signing env vars)
./gradlew assembleDebug -PclassnexApiUrl=http://10.0.2.2:8081/api/v1/   # debug build against a local test server (emulator)
```

Requires JDK 17 and the Android SDK (platform 35). The debug build allows plain HTTP **only** to `10.0.2.2` / `127.0.0.1` (emulator → local test server); release builds are HTTPS-only.

## Emulator UI tests and screenshots

Workflow `.github/workflows/android-ui.yml` starts PHP 8.2 + MySQL 8, seeds a test database (`tests/e2e_final.php` + `tests/android_ui_seed.php`), boots an API 30 emulator and runs `UiFlowTest` (`app/src/androidTest`): auth screens, Class Admin journey (dashboard, drawer, students, profile, attendance save, fees, collect + receipt, payments, branches, activities, batches, teachers, fee plans, discounts, timetable, history, announcements, reminders, reports, subscription, academy profile, settings, support, notifications), teacher (checks the menu hides Branches / Subscription), parent and Super Admin. Each step is logged PASS / FAIL with its duration and a screenshot is taken of every screen; both are published to the GitHub release **android-screenshots** (`ClassNex-Android-Screenshots.zip`, `ui-steps.txt`).

**Latest result (commit f6378b0, run 45061533025):** 5 / 5 tests passed, **45 / 45 steps PASS**, 59 screenshots – on the API 30 emulator (Pixel 6 profile). Bugs found by these runs and fixed: keyboard staying open over the dashboard after login, system back closing the app instead of the drawer, dashboard list crash on "recent messages" (duplicate list keys), module tabs not switching (Super Admin).

Test logins (test database only): Class Admin 9845000001 / Asha@1234 · Teacher 9845100001 / Teach@123 · Parent 9845200001 / Parent@123 · Super Admin qa-admin@classnex.test / Admin@12345.

## Building with GitHub Actions

Workflow: `.github/workflows/android.yml` (runs on every push to `android/**`, or manually from the *Actions* tab → *Android build* → *Run workflow*).

- **API contract tests** – `ApiContractTest` parses real API responses (`app/src/test/resources/fixtures`, captured with `tests/android_fixtures.php`) with the app's own models; the build stops if the server and the app disagree.
- **Debug APK** – always built. Installable side-by-side with the release app (`in.classnex.app.debug`).
- **Release APK + AAB** – built with R8; signed when the signing secrets exist, otherwise unsigned.
- **Download**: every successful build on `main` uploads `ClassNex-debug.apk`, `ClassNex-release.apk` and `ClassNex-release.aab` to the GitHub release **android-latest** (Releases page – the link does not expire). Run artifacts are also kept for 90 days.

### Create the signing key (once)

```bash
keytool -genkeypair -v -keystore classnex-release.jks -alias classnex -keyalg RSA -keysize 2048 -validity 10000
base64 -w0 classnex-release.jks > classnex-release.jks.b64     # macOS: base64 -i classnex-release.jks
```

GitHub → repository → Settings → Secrets and variables → Actions:
- `CLASSNEX_KEYSTORE_BASE64` = contents of `classnex-release.jks.b64`
- `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS` (`classnex`), `CLASSNEX_KEY_PASSWORD`
- (optional) Variable `CLASSNEX_API_URL` – only if the API address ever changes

Keep the `.jks` file and passwords safe offline — Google Play requires the same key (or Play App Signing upload key) for every update.

## Building locally

Android Studio (Koala or newer) → open the `android/` folder → run. Or:

```bash
cd android
./gradlew testDebugUnitTest assembleDebug          # uses https://genusitsolution.com/ClassNex/api/v1/
# signed release (keystore.properties: storeFile, storePassword, keyAlias, keyPassword)
./gradlew assembleRelease bundleRelease
```

For a local backend use your PC's LAN IP over HTTPS (cleartext HTTP is disabled in the app for security).

## Google Play checklist

1. Create the app in Play Console (package `in.classnex.app`), enable **Play App Signing**.
2. Upload the `.aab` to an internal testing track first.
3. Data safety form: the app collects name, phone, email, photos and optional documents for the academy's service, encrypted in transit; no ads, no data sale. Children's data is processed on behalf of the academy with guardian consent.
4. Privacy policy URL: `https://genusitsolution.com/ClassNex/page.php?p=privacy-policy`.
5. Camera: the app uses the system camera app (no CAMERA permission). Notifications permission is requested on Android 13+.

## Security notes

- The bearer token is stored with EncryptedSharedPreferences (AES-256, Keystore-backed); app data is excluded from cloud backup.
- Any `401` from the server clears the session and returns to the login screen.
- Student photos and receipts are fetched with the authenticated client and cached only in the app's private cache.
- OCR runs on the device; only the extracted text goes to the server, which masks identity numbers and returns suggestions for the user to confirm.

## Installing the APK on a phone

1. On the phone open the GitHub Releases page → **android-latest** → download `ClassNex-debug.apk` (or the signed release APK once signing secrets are set).
2. Allow *Install unknown apps* for the browser when Android asks, install, open, log in with a Class Admin / teacher / parent account.

## Verification status

- 1.0.22: emulator UI flow 44/44 passed (API 30), backend suites all green – see QA_1.0.22.md.
- Compiles, passes the API contract tests and builds debug + release APK/AAB in GitHub Actions (see QA_REPORT.md for run IDs).
- **Not yet tested on a physical Android device** (no device was available to the developer). Please run the checklist in
  QA_REPORT.md → *Physical device checklist* on one or two real phones before publishing on Google Play.
