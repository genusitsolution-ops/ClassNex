# ClassNex Android App

One native app (`in.classnex.app`) for every role. After login the app shows the menu, start screen and actions for the signed-in user's role and permissions — exactly the same rules as the web, because both use the same REST API.

| Role | Screens |
|---|---|
| Class Admin | Dashboard (academy-wide student quota, quick actions), **Branches** (list with per-branch and combined figures, add / edit / deactivate, branch detail with students, batches, teachers, attendance, collection and pending fees, branch reports, **assign students** with server-side search, paging and multi-select), students (branch filter, profile with branch, **Change branch** keeping the Student ID), quick registration (camera photo, gallery, document scan + OCR pre-fill, optional branch, **possible-duplicate dialog**: open / link existing / separate student with reason), **present-by-default** attendance, **attendance history** (date presets, branch / batch / activity / status filters, by class / student / branch, totals, PDF / Excel / CSV), collect fee with receipt, pending fees, payments, reports (branch-wise and combined), messages, **WhatsApp click-to-send** and WhatsApp history (manual mode), notifications |
| Teacher | Today's classes, attendance for assigned batches, attendance history of those batches, assigned students; fees only if the Class Admin granted permission |
| New academy | **Register your academy** from the login screen (same rules as the website: waits for Super Admin approval) |
| Parent / Student | Child switcher, home (today, month %, next class, dues, last receipt), attendance by month, fees & receipt downloads, notifications |
| Super Admin | Platform numbers and academies list; full management opens the web panel |

**Stack:** Kotlin 2.0, Jetpack Compose (Material 3), MVVM with StateFlow/SharedFlow, Hilt, Retrofit + OkHttp + kotlinx.serialization, Coroutines, Coil (authenticated image loading), ML Kit Text Recognition (on-device OCR), Firebase Cloud Messaging, EncryptedSharedPreferences (Android Keystore). Min SDK 24, target SDK 35.

## Configuration

| Setting | How |
|---|---|
| Backend URL | One setting: Gradle property `classnexApiUrl` in `android/gradle.properties` = **`https://genusitsolution.com/ClassNex/api/v1/`** (must end with `/api/v1/`). It becomes `BuildConfig.API_BASE_URL`, used by Retrofit, downloads and the “open web panel” link – no URL is written in any screen. Override per build with `-PclassnexApiUrl=…`, or in GitHub with the repository **variable** `CLASSNEX_API_URL`. |
| Push notifications | Repository **secret** `GOOGLE_SERVICES_JSON` = full content of `google-services.json`. Without it the app builds and works; push is simply disabled. |
| Release signing | Secrets `CLASSNEX_KEYSTORE_BASE64`, `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS`, `CLASSNEX_KEY_PASSWORD` |

Never commit keystores, passwords or `google-services.json` (they are in `.gitignore`).

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

- Compiles, passes the API contract tests and builds debug + release APK/AAB in GitHub Actions (see QA_REPORT.md for run IDs).
- **Not yet tested on a physical Android device** (no device was available to the developer). Please run the checklist in
  QA_REPORT.md → *Physical device checklist* on one or two real phones before publishing on Google Play.
