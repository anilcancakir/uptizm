/// Deep-link configuration (iOS Universal Links, Android App Links).
///
/// `enabled` was believed to switch the feature off, but the runtime driver
/// never read this key, so the previous `false` here left the feature live
/// and merely handler-less. `magic_deeplink`'s `generate` command does read
/// this file: it composes `apple-app-site-association` from
/// `team_id`/`bundle_id` and `assetlinks.json` from
/// `package_name`/`sha256_fingerprints`, so a wrong value here still produces
/// an association file and a success exit code, just one that names the
/// wrong identity.
///
/// `domain` is `app.uptizm.com`, not the marketing site: `deploy/README.md`'s
/// host map says `uptizm.com` serves the landing page and status pages while
/// `app.uptizm.com` serves the compiled Flutter client, which is the only
/// host that actually has a route for a link like `/incidents/{id}`.
///
/// `ios.team_id` is copied from `ios/Runner.xcodeproj/project.pbxproj`'s
/// `DEVELOPMENT_TEAM` (it appears three times, always the same value); it is
/// already public by being committed there, so repeating it here discloses
/// nothing new.
///
/// `android.sha256_fingerprints` carries the DEBUG signing certificate's
/// fingerprint (`keytool -list -v -keystore ~/.android/debug.keystore
/// -alias androiddebugkey -storepass android -keypass android`), which is
/// likewise public by construction: the debug keystore is a fixed fixture
/// shipped with the Android SDK, not a secret. The RELEASE fingerprint is
/// genuinely absent rather than merely unfilled: `android/app/build.gradle.kts`
/// signs the release build with the debug key too, and there is no release
/// certificate yet. The list can hold several fingerprints, so the release
/// one is added beside this one once it exists, not substituted for it.
Map<String, dynamic> get deeplinkConfig => {
  'deeplink': {
    'enabled': true,
    'driver': 'app_links',
    'domain': 'app.uptizm.com',
    'scheme': 'https',

    'ios': {'team_id': '883V9SVA54', 'bundle_id': 'com.uptizm.uptizm'},

    'android': {
      'package_name': 'com.uptizm.uptizm',
      'sha256_fingerprints': [
        '34:4B:42:E4:67:4F:60:01:BA:D5:E2:29:E8:09:0C:45:7D:6E:D1:C4:6D:E8:CA:CE:74:55:85:09:D6:2F:55:86',
      ],
    },

    'paths': ['/*'],
  },
};
