# iPhone builds and testing (TestFlight)

GitHub Actions builds both apps for iPhone on a Mac runner (`.github/workflows/ios.yml`) on every push that touches the apps.

- **Without Apple secrets:** an unsigned release build — it proves both apps compile for iOS. Nothing to install.
- **With the secrets below:** each build is signed automatically and uploaded to App Store Connect. About 10–30 minutes later it appears in **TestFlight**, and anyone you add as a tester installs it with Apple's TestFlight app.

## One-time setup (in your Apple account)

1. **Apple Developer Program** membership for the account that owns the apps. Note the **Team ID** (developer.apple.com → Account → Membership).
2. **Bundle IDs.** To update the existing Ride Coop iPhone apps, use their existing bundle IDs (App Store Connect → the app → App Information → Bundle ID). For new apps, register two IDs (e.g. `net.ridecoop.rider`, `net.ridecoop.driver`) under Certificates, Identifiers & Profiles, and create the two apps in App Store Connect.
3. **App Store Connect API key:** App Store Connect → Users and Access → Integrations → App Store Connect API → Team Keys → **+**, access **Admin** (needed so the build can create its signing certificate and profiles). Download the `.p8` file (only once), and note the **Key ID** and **Issuer ID**.

## GitHub settings (repository → Settings → Secrets and variables → Actions)

Secrets:

| Name | Value |
|---|---|
| `APPLE_TEAM_ID` | Team ID |
| `APPSTORE_API_KEY_ID` | Key ID |
| `APPSTORE_API_ISSUER_ID` | Issuer ID |
| `APPSTORE_API_KEY_P8` | the whole text of the `.p8` file, including the BEGIN/END lines |
| `GOOGLE_SERVICE_INFO_PLIST_RIDER` / `_DRIVER` | optional, for push on iPhone (see below) |

Variables (the Variables tab):

| Name | Value |
|---|---|
| `IOS_BUNDLE_ID_RIDER` | the rider app's bundle ID |
| `IOS_BUNDLE_ID_DRIVER` | the driver app's bundle ID |

Then run the workflow (Actions → iOS apps → Run workflow) or push a change. Each upload gets the build number of the run.

## Installing on an iPhone

1. App Store Connect → the app → **TestFlight** → Internal Testing → add yourself and your team (they must be users in App Store Connect; internal builds need no Apple review).
2. On the iPhone, install **TestFlight** from the App Store, open the invitation email, and install Ride Coop / Ride Coop Driver.
3. For people outside your App Store Connect team, use External Testing (Apple reviews the first build, usually within a day).

## Still to do for iPhone

- **Push notifications:** add the two iPhone apps to the Firebase project (same bundle IDs), upload an APNs key in Firebase (Project settings → Cloud Messaging), and put each app's `GoogleService-Info.plist` in the secrets above. The apps then also need the Push Notifications capability switched on — one small follow-up change once the Firebase files exist.
- **Google Maps:** if the Maps key is restricted to apps, add the two bundle IDs to it in Google Cloud Console.
