import 'package:flutter/widgets.dart' show Locale;

import 'package:magic/magic.dart';

import '../models/user.dart';
import 'device_timezone_service.dart';

/// Applies device- and user-derived locale/timezone signals to magic's
/// localization runtime.
///
/// Bundles two independent hooks that both feed data magic cannot reliably
/// determine on its own:
///
/// 1. [applyDetectedTimezone] — a BOOT hook. Nothing in the framework calls
///    `DateManager.instance.boot()` automatically, so this calls it
///    (idempotent) to initialize the IANA timezone database and apply the
///    configured fallback, then overrides the result with
///    [DeviceTimezoneService]'s reliable platform-native IANA id; magic's own
///    `DateManager.detectTimezone()` only reads `DateTime.now().timeZoneName`,
///    a locale-dependent abbreviation, not an IANA identifier.
/// 2. [syncLocaleWithAuthState] — a POST-LOGIN hook. Mirrors
///    `RealtimeService.syncWithAuthState`: idempotent and safe to call on
///    every `Auth.stateNotifier` bump. Pre-login, magic's own
///    `auto_detect_locale` already renders the device locale; once a user is
///    authenticated and carries a persisted `locale` preference, this applies
///    it via `Lang.setLocale` so the app renders in the user's language.
class LocaleApplicationService {
  /// Creates the service.
  ///
  /// [timezoneService] defaults to a fresh [DeviceTimezoneService]; tests
  /// inject a fake to avoid depending on the real platform channel.
  LocaleApplicationService({DeviceTimezoneService? timezoneService})
    : _timezoneService = timezoneService ?? DeviceTimezoneService();

  /// The device timezone detector, injected for testability.
  final DeviceTimezoneService _timezoneService;

  /// Boots [DateManager] and feeds it the device's detected IANA timezone.
  ///
  /// A no-op override when detection fails: [DateManager] keeps whatever
  /// timezone its own boot logic resolved (the config default, or its own
  /// weaker abbreviation-based guess).
  Future<void> applyDetectedTimezone() async {
    await DateManager.instance.boot();

    final String? detected = await _timezoneService.detect();
    if (detected != null) {
      DateManager.instance.setTimezone(detected);
    }
  }

  /// Applies the authenticated user's persisted locale to [Lang].
  ///
  /// A no-op when logged out, when the user carries no `locale` preference,
  /// or when the locale is already applied.
  Future<void> syncLocaleWithAuthState() async {
    if (!Auth.check()) return;

    final String? locale = User.current.locale;
    if (locale == null || locale.isEmpty) return;
    if (Lang.current.languageCode == locale) return;

    // reload: false because the root MaterialApp.locale is bound to the runtime
    // Translator (see main.dart), so notifying is enough to rebuild the app in
    // the new locale; a Magic.reload() remount would be a redundant full reset.
    await Lang.setLocale(Locale(locale), reload: false);
  }
}
