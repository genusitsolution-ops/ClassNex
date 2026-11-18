# ClassNex Android App

One native app (`in.classnex.app`) for every role. After login the app shows the menu, start screen and actions for the signed-in user's role and permissions — exactly the same rules as the web, because both use the same REST API.

| Role | Screens |
|---|---|
| Owner | Dashboard with quick actions, students (search, profile, call/WhatsApp), quick registration (camera photo, gallery, document scan + OCR pre-fill), attendance, collect fee with receipt (view/print/share/WhatsApp), pending fees, payments, reminders/messages, notifications, profile |
| Teacher | Today's classes, attendance for assigned batches, assigned students; fees only if the owner granted permission |
| Parent / Student | Child switcher, home (today, month %, next class, dues, last receipt), attendance by month, fees & receipt downloads, notifications |
| Super Admin | Platform numbers and academies list; full management opens the web panel |

**Stack:** Kotlin 2.0, Jetpack Compose (Material 3), MVVM with StateFlow/SharedFlow, Hilt, Retrofit + OkHttp + kotlinx.serialization, Coroutines, Coil (authenticated image loading), ML Kit Text Recognition (on-device OCR), Firebase Cloud Messaging, EncryptedSharedPreferences (Android Keystore). Min SDK 24, target SDK 35.

## Configuration

| Setting | How |
|---|---|
| Backend URL | Gradle property `classnexApiUrl` (must end with `/api/v1/`). In GitHub: repository **variable** `CLASSNEX_API_URL`, or the *api_url* input when running the workflow manually. |
| Push notifications | Repository **secret** `GOOGLE_SERVICES_JSON` = full content of `google-services.json`. Without it the app builds and works; push is simply disabled. |
| Release signing | Secrets `CLASSNEX_KEYSTORE_BASE64`, `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS`, `CLASSNEX_KEY_PASSWORD` |

Never commit keystores, passwords or `google-services.json` (they are in `.gitignore`).

## Building with GitHub Actions

Workflow: `.github/workflows/android.yml` (runs on every push to `android/**`, or manually from the *Actions* tab → *Android build* → *Run workflow*).

- **Debug APK** – always built; download the `classnex-debug-apk` artifact from the run page. Installable side-by-side with the release app (`in.classnex.app.debug`).
- **Release APK + AAB** – built and signed when the signing secrets exist; artifact `classnex-release` contains the `.apk` (direct install) and `.aab` (Google Play upload).

### Create the signing key (once)

```bash
keytool -genkeypair -v -keystore classnex-release.jks -alias classnex -keyalg RSA -keysize 2048 -validity 10000
base64 -w0 classnex-release.jks > classnex-release.jks.b64     # macOS: base64 -i classnex-release.jks
```

GitHub → repository → Settings → Secrets and variables → Actions:
- `CLASSNEX_KEYSTORE_BASE64` = contents of `classnex-release.jks.b64`
- `CLASSNEX_KEYSTORE_PASSWORD`, `CLASSNEX_KEY_ALIAS` (`classnex`), `CLASSNEX_KEY_PASSWORD`
- Variable `CLASSNEX_API_URL` = `https://www.your-domain.com/api/v1/`

Keep the `.jks` file and passwords safe offline — Google Play requires the same key (or Play App Signing upload key) for every update.

## Building locally

Android Studio (Koala or newer) → open the `android/` folder → run. Or:

```bash
cd android
./gradlew assembleDebug -PclassnexApiUrl=https://www.your-domain.com/api/v1/
# signed release (keystore.properties: storeFile, storePassword, keyAlias, keyPassword)
./gradlew assembleRelease bundleRelease -PclassnexApiUrl=https://www.your-domain.com/api/v1/
```

For a local backend use your PC's LAN IP over HTTPS (cleartext HTTP is disabled in the app for security).

## Google Play checklist

1. Create the app in Play Console (package `in.classnex.app`), enable **Play App Signing**.
2. Upload the `.aab` to an internal testing track first.
3. Data safety form: the app collects name, phone, email, photos and optional documents for the academy's service, encrypted in transit; no ads, no data sale. Children's data is processed on behalf of the academy with guardian consent.
4. Privacy policy URL: `https://your-domain/page.php?p=privacy-policy`.
5. Camera: the app uses the system camera app (no CAMERA permission). Notifications permission is requested on Android 13+.

## Security notes

- The bearer token is stored with EncryptedSharedPreferences (AES-256, Keystore-backed); app data is excluded from cloud backup.
- Any `401` from the server clears the session and returns to the login screen.
- Student photos and receipts are fetched with the authenticated client and cached only in the app's private cache.
- OCR runs on the device; only the extracted text goes to the server, which masks identity numbers and returns suggestions for the user to confirm.
