# ClassNex Android – Release 1.0.22

Date: 23 Nov 2026 · Status: built and emulator-tested, **not deployed to Google Play** · Physical-device testing: **not performed**

## 1. Version and configuration record

| Item | Previous build | This build |
|---|---|---|
| versionName | 1.0.21 (never deployed) | **1.0.22** |
| versionCode | 21 | **22** |
| Package ID (applicationId) | `in.classnex.app` | `in.classnex.app` (unchanged) |
| Debug package | `in.classnex.app.debug` | `in.classnex.app.debug` (unchanged) |
| Git commit | `a79cad3` | `bf2a659` (redesign) + `030e9aa` (test/CI fix) |
| API base URL | `https://genusitsolution.com/ClassNex/api/v1/` | unchanged |
| min / target / compile SDK | 24 / 35 / 35 | unchanged |

Version source: `android/app/build.gradle.kts` – `versionCode` defaults to `22` and `versionName` to `1.0.22`. Both can be overridden by the `CLASSNEX_VERSION_CODE` / `CLASSNEX_VERSION_NAME` environment variables, but CI no longer overrides them, so every build of this commit is identifiable as 1.0.22 (build 22). The More screen shows the version (`v1.0.22-debug` in the debug app).

### Signing
Release signing is read from environment variables (`CLASSNEX_KEYSTORE_PATH`, `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS`, `CLASSNEX_KEY_PASSWORD`) or from an uncommitted `android/keystore.properties`. **No keystore or password exists in the repository or in the CI secrets**, so:
- `ClassNex-debug.apk` is signed with the standard Android debug key and installs directly.
- `ClassNex-release.apk` and `ClassNex-release.aab` are **unsigned**. Sign them with your own upload keystore before publishing (see §3). No signing credentials were invented.

### Git state
- Branch `main`, history intact; no reset, no force-push, no commits removed.
- 1.0.21 source is preserved at commit `a79cad3` (tag it if you wish: `git tag android-1.0.21 a79cad3`).
- GitHub releases: `android-v1.0.22` (1.0.22 builds), `android-latest` (newest build), `android-screenshots` (emulator test output). An `android-v1.0.21` release could not be created by the CI token (HTTP 403), so the 1.0.21 builds are kept inside this package at `previous-builds/1.0.21/` instead.

## 2. What changed in 1.0.22

Design (following the supplied reference):
- Light top bars with round icon buttons; brand header on Home; back arrow on sub-screens; "+ Add" pill on Students.
- Bottom navigation with an animated filled purple pill (Home · Students · Attendance · Fees · More).
- Home: "Hello, <name>" hero card with trophy art and branch selector; quick-action icon tile grid; "Today at a Glance" donut with counting numbers; fee card with collected amount, Pending / Overdue / Today tiles and monthly bar chart; fees due this week.
- Students: search + filter button (bottom sheet: branch, activity, batch), All / Active / Inactive chips with live counts, rich rows with status badge and amount due.
- Mark Attendance: folded class selector, green guidance banner, 4 count tiles (Present / Absent / Late / Leave), one status pill per student, All Present / All Absent, Save Attendance.
- Fees: Collect Fee / Pending / Overdue tabs; student search; student card with Pending / Overdue / Advance and Collect Fee; "This month" summary with payment methods; Recent Payments.
- More: profile header card + grouped tiles – Academy Management, Operations, Finance & Reports, Communication, Account.

Motion: screen transitions, nav pill animation, count-up numbers, animated donut and bar charts, bottom sheets, pull-to-refresh on Home. All animations switch off when the phone's "Remove animations" / animator scale is 0.

New Class Admin features (web parity, all on existing API endpoints): Receipts & IDs settings, Online admission settings, Online payments (Razorpay keys), Activity log, payment refund, cancel a fee (invoice), unlink a parent, enrol a student in another activity, student list filters by status / activity / batch. See `FEATURE_COMPARISON_1.0.22.md`.

Not changed: package ID, API URL, authentication/session, quota logic, branch access control, backend.

## 3. Build and install

### Install the ready APK
1. Copy `builds/ClassNex-1.0.22-debug.apk` to the phone and open it (allow "Install unknown apps"). It installs beside any Play version because its package is `in.classnex.app.debug`.

### Build in Android Studio
1. Open the `source/android` folder (Android Studio Ladybug or newer, JDK 17).
2. Let Gradle sync, then Run ▶ (debug) or `./gradlew assembleDebug`.
3. Release: `./gradlew assembleRelease bundleRelease` – unsigned unless signing values are provided.

### Sign the release
Create `android/keystore.properties` (never commit it):
```
storeFile=/path/to/upload-keystore.jks
storePassword=...
keyAlias=...
keyPassword=...
```
or set the `CLASSNEX_KEYSTORE_*` variables / GitHub secrets, then rebuild. Alternatively sign the existing unsigned files: `apksigner sign --ks upload.jks ClassNex-1.0.22-release-unsigned.apk` and `jarsigner -keystore upload.jks ClassNex-1.0.22-release-unsigned.aab <alias>`.

### Backend
No backend changes in 1.0.22 beyond those already shipped with 1.0.21 (reminder ON/OFF and WhatsApp choice). Deploy steps: `docs/DEPLOYMENT.md`.

## 4. Files modified for 1.0.22 (since 1.0.21 commit `d1fa2dc`)
```
.github/workflows/android.yml
android/app/build.gradle.kts
android/app/src/androidTest/java/in/classnex/app/UiFlowTest.kt
android/app/src/main/java/in/classnex/app/MainActivity.kt
android/app/src/main/java/in/classnex/app/data/net/ApiService.kt
android/app/src/main/java/in/classnex/app/ui/academy/AttendanceAndFees.kt
android/app/src/main/java/in/classnex/app/ui/academy/AttendanceFlow.kt
android/app/src/main/java/in/classnex/app/ui/academy/DashboardAndStudents.kt
android/app/src/main/java/in/classnex/app/ui/academy/MoreScreens.kt
android/app/src/main/java/in/classnex/app/ui/admin/AdminAndCommon.kt
android/app/src/main/java/in/classnex/app/ui/common/Common.kt
android/app/src/main/java/in/classnex/app/ui/common/Components.kt
android/app/src/main/java/in/classnex/app/ui/crud/Modules.kt
android/app/src/main/java/in/classnex/app/ui/nav/AppNav.kt
docs/RELEASE_1.0.22.md (new)
docs/FEATURE_COMPARISON_1.0.22.md (new)
docs/QA_1.0.22.md (new)
docs/ANDROID.md, docs/DELIVERY_REPORT.md (updated)
```
No new source files were needed; new UI components live in `ui/common/Components.kt`.
