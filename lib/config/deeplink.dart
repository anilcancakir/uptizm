/// Deep-link configuration (iOS Universal Links, Android App Links).
///
/// **Disabled until the two platform secrets below are real.** The identifiers
/// here were the package's scaffold defaults (`example.com`, `YOUR_TEAM_ID`,
/// `com.example.app`, `YOUR_SHA256_FINGERPRINT`) while `enabled` was `true`,
/// so this file read as configured and was loaded on every boot.
///
/// The runtime driver ignores these keys, which is why nothing ever surfaced
/// the stub. The generator does not: `magic_deeplink`'s `generate` command
/// composes `apple-app-site-association` from `team_id`/`bundle_id` and
/// `assetlinks.json` from `package_name`/`sha256_fingerprints`. Run against the
/// scaffold it published association files claiming
/// `YOUR_TEAM_ID.com.example.app`, and every Universal or App Link then opens
/// the browser instead of the app, silently, on both platforms.
///
/// The three values this repository already knows are filled in: the host from
/// `.env.production`'s `WEB_URL`, and the bundle id and package name from
/// `ios/Runner.xcodeproj` and `android/app/build.gradle.kts`, which both say
/// `com.uptizm.uptizm`.
///
/// Two are secrets no file here carries, so they are left named rather than
/// invented:
///
/// - `ios.team_id`: the Apple Developer team id, from the membership page of
///   the account that signs the app.
/// - `android.sha256_fingerprints`: the SHA-256 of the RELEASE signing
///   certificate, from `keytool -list -v -keystore <release.jks>` (or Play
///   App Signing, whose fingerprint is the one Google serves).
///
/// Fill both, flip `enabled` to `true`, then run the generator and publish the
/// two association files. Leaving it disabled is the honest state: a deep link
/// that does not open the app is better than an association file that claims
/// an identity we do not hold.
Map<String, dynamic> get deeplinkConfig => {
  'deeplink': {
    'enabled': false,
    'driver': 'app_links',
    'domain': 'uptizm.com',
    'scheme': 'https',

    'ios': {'team_id': '', 'bundle_id': 'com.uptizm.uptizm'},

    'android': {
      'package_name': 'com.uptizm.uptizm',
      'sha256_fingerprints': <String>[],
    },

    'paths': ['/*'],
  },
};
