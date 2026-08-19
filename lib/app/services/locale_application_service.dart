import 'package:flutter/widgets.dart' show Locale, WidgetsBinding;

import 'package:magic/magic.dart';

import '../models/user.dart';

/// Applies the authenticated user's locale preference to magic's
/// localization runtime.
///
/// A POST-LOGIN hook. Mirrors `RealtimeService.syncWithAuthState`: idempotent
/// and safe to call on every `Auth.stateNotifier` bump. Pre-login, magic's
/// own `auto_detect_locale` already renders the device locale; once a user is
/// authenticated and carries a persisted `locale` preference, this applies
/// it via `Lang.setLocale` so the app renders in the user's language.
class LocaleApplicationService {
  /// Applies the authenticated user's persisted locale to [Lang].
  ///
  /// A no-op when logged out, when the user carries no `locale` preference,
  /// or when the locale is already applied.
  Future<void> syncLocaleWithAuthState() async {
    if (!Auth.check()) return;

    final String? locale = User.current.locale;
    if (locale == null || locale.isEmpty) return;
    if (Lang.current.languageCode == locale) return;

    // Two steps, because loading the catalogue is only half the job.
    // `Translator.setLocale` notifies nobody, and `MagicApplication` reads
    // `Lang.current` during build (`_resolveRuntimeLocale`), so the new locale
    // reaches `MaterialApp.locale` on the next build and nothing asks for one.
    // Widgets already on screen keep their old strings: a Turkish account
    // logging in got a Turkish dashboard under a bottom tab bar still reading
    // Home / Monitors / Incidents / Status, because the routed page was new and
    // the shell was not.
    await Lang.setLocale(Locale(locale), reload: false);
    _requestRebuild();
  }

  /// Asks magic to remount the app so mounted widgets re-read [Lang].
  ///
  /// After the current frame, not during it: this runs from an
  /// `Auth.stateNotifier` listener, and `Magic.reload()` swaps the key above
  /// the whole tree, which must not happen while that tree is building.
  ///
  /// The `scheduleFrame` is load-bearing, not defensive.
  /// `addPostFrameCallback` registers a callback for the next frame without
  /// requesting one, and an app sitting idle after a login has stopped
  /// producing them, so on its own the callback can wait indefinitely.
  void _requestRebuild() {
    WidgetsBinding.instance.addPostFrameCallback((_) => Magic.reload());
    WidgetsBinding.instance.scheduleFrame();
  }
}
