import 'package:flutter/widgets.dart' show Locale;

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

    // reload: false because MagicApplication's own MaterialApp.locale already
    // follows the runtime Translator (magic_app_widget.dart), so notifying is
    // enough to rebuild the app in the new locale; a Magic.reload() remount
    // would be a redundant full reset.
    await Lang.setLocale(Locale(locale), reload: false);
  }
}
