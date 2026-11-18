# Firebase Push Notifications Setup

Push notifications reach the Android app (Class Admins, teachers, parents, students). They are optional: without
Firebase everything still appears in the in-app inbox (bell icon).

## 1. Create the Firebase project
1. console.firebase.google.com → *Add project* (e.g. “ClassNex”). Google Analytics is not needed.
2. *Add app → Android*:
   - package name `in.classnex.app` (release build)
   - add a second Android app `in.classnex.app.debug` if you also want push in the debug build.
3. Download **google-services.json** (it contains both apps if you added both).

## 2. Give the Android build the file (never commit it)
- **GitHub Actions**: repository *Settings → Secrets and variables → Actions → New secret*
  `GOOGLE_SERVICES_JSON` = the whole content of the file. The workflow writes it to `android/app/` before building.
- **Android Studio**: copy the file to `android/app/google-services.json` (git-ignored).
Builds without the file still work; push is simply disabled in that build.

## 3. Give the server the sending key
1. Firebase console → ⚙ *Project settings → Service accounts → Generate new private key* (downloads a JSON file).
2. ClassNex → *Super Admin → Integrations → Firebase* → paste the JSON (it is stored encrypted) → *Send test* to your
   own logged-in phone → switch to **Live**.

ClassNex uses the **FCM HTTP v1** API with a short-lived OAuth token created from the service account (the legacy
server key is not used). Device tokens are registered by the app after login and removed on logout or when Firebase
reports them invalid.

## 4. Check
Log in on the phone, then from the web panel send a message with the *Push* channel to that user's student/batch;
the notification arrives and *Message Log* shows it as sent.
