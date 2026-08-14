import 'package:sentry_flutter/sentry_flutter.dart';

import '../app/support/env_strings.dart';

/// Sentry Configuration.
///
/// Unlike the other files in this directory, this one is NOT a magic config
/// map: the Sentry SDK is initialised directly in `main()` rather than through
/// `Magic.init`, because it has to be running before the framework boots in
/// order to report a failure during boot.
///
/// ## Nothing leaves a development machine
///
/// [sentryDsn] answers an empty string unless `APP_ENV` is exactly
/// `production`, and an empty DSN leaves the SDK inert: every `captureEvent`,
/// every breadcrumb and every span becomes a no-op. That is the whole
/// mechanism, and it is structural rather than procedural, so it survives
/// someone pasting the production DSN into their local `.env` while debugging.
///
/// The two `.env` files make it work without ceremony: the development one
/// carries `APP_ENV=local` and no DSN, and `.env.production` (copied over `.env`
/// at build time, see `deploy/README.md`) carries both.
const String _productionEnvironment = 'production';

/// How many traces are kept, and why this number.
///
/// The client's traces continue into the API: `sentry_dio` propagates a
/// `sentry-trace` header and the Laravel sampler INHERITS whatever decision
/// arrives, so this rate governs both halves of every user-initiated request.
/// Raising it does not just cost client spans, it multiplies server spans too.
///
/// 0.1 against this org's 50M monthly span allowance leaves comfortable room:
/// a thousand daily users at fifty navigations each is ~1.5M transactions a
/// month, roughly 15M spans before sampling and 1.5M after.
const double _tracesSampleRate = 0.1;

/// Whether this build reports to Sentry at all.
bool get sentryEnabled => sentryDsn.isNotEmpty;

/// The DSN, or an EMPTY STRING on every build that is not production.
///
/// The return type is deliberately `String` and never `String?`, and the
/// disabled value is deliberately `''` rather than null. `Sentry.init` throws
/// `ArgumentError('DSN is required.')` when the DSN is NULL, while an empty
/// string is a documented, supported "do not send anything" (see
/// `SentryOptions.dsn`). Since `appRunner` runs inside that call, making this
/// nullable would stop the entire app from booting on every developer machine
/// and in the whole test suite, and the failure would look like a Sentry
/// configuration error rather than what it is.
String get sentryDsn {
  if (envString('APP_ENV', 'local') != _productionEnvironment) {
    return '';
  }

  return envString('SENTRY_DSN', '');
}

/// Apply this app's Sentry options.
///
/// Passed to `SentryFlutter.init` in `main()`.
void configureSentry(SentryFlutterOptions options) {
  options.dsn = sentryDsn;
  options.environment = envString('APP_ENV', 'local');

  // Set explicitly because Flutter WEB has no default: the SDK derives
  // `package@version+build` from the platform manifest on iOS and Android, and
  // web has no manifest to read. Web is this product's primary surface, so
  // leaving it unset would mean the surface that matters most is also the one
  // whose issues cannot be tied to a deploy. Empty is tolerated (the SDK simply
  // files events with no release) rather than fatal.
  final String release = envString('SENTRY_RELEASE', '');

  if (release.isNotEmpty) {
    options.release = release;
  }

  options.tracesSampleRate = _tracesSampleRate;

  // The API's bearer token is the obvious secret here, and the SDK already
  // strips `Authorization` unconditionally through its own `HttpSanitizer`,
  // regardless of this flag. This stays false for everything else it would
  // otherwise attach: the user's address, and request bodies that in this
  // product can carry a customer's own monitored content.
  options.sendDefaultPii = false;

  // Release health: how many sessions a version ran without a crash. It is the
  // one signal that says a deploy made things worse, and it is cheap.
  //
  // ON WEB THIS DEPENDS ON ROUTE NAMES, which is not obvious and fails without
  // a symptom. `WebSessionHandler.startSession` fires only when the name of the
  // route CHANGES, or on the very first navigation when that name is exactly
  // `/`, and it reads `RouteSettings.name` rather than `GoRoute.name`. A router
  // that leaves pages unnamed therefore reports zero sessions forever while its
  // transport keeps working perfectly. Measured here before it was fixed: a
  // browser with no ad blocker made zero ingest requests across three route
  // changes, while a forced capture from the same page returned 200.
  //
  // magic names its pages as of fluttersdk/magic#121. Until that lands in the
  // version this app resolves, release health stays empty and error reporting
  // is unaffected.
  options.enableAutoSessionTracking = true;

  // NOT ENABLED, each for a measured reason rather than an oversight:
  //
  // - Session Replay is iOS/Android only. This app's primary surface is web,
  //   so it would cover the minority of traffic while adding a whole PII
  //   review, and its quota here is 50 replays a month.
  // - Screenshot attachment renders a monitoring dashboard, which is customer
  //   data by definition.
  // - Profiling has no web implementation at all.
  //
  // AD BLOCKERS ARE A REAL AND UNFIXABLE HOLE IN THE WEB NUMBERS, and it is
  // worth knowing before anyone reads client error counts as coverage.
  //
  // Since v9 this SDK loads the Sentry Browser JS bundle from
  // `browser.sentry-cdn.com`, and that URL is hardcoded in `sentry_js_bundle.dart`
  // (marked `@internal`, not exported, not configurable through any option).
  // Blocklists match it by DOMAIN, so a blocked visitor never fetches the
  // script and the SDK silently never initialises: `WebSdkIntegration` catches
  // the fetch failure, logs it, and does not rethrow.
  //
  // The usual answer, a same-origin tunnel, does NOT fix this. It would move
  // only the event-sending leg; the script fetch is a separate request to a
  // separate blocked domain. And there is nothing to configure anyway:
  // sentry-dart has no `tunnel` option (getsentry/sentry-dart#872, open since
  // 2022) and never forwards one to the JS SDK it injects.
  //
  // Backend and worker reporting are untouched by this, being server to
  // server. Client-side counts are a floor, not a measurement.
}
