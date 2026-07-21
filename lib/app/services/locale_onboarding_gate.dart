import 'package:flutter/foundation.dart' show visibleForTesting;

import 'package:magic/magic.dart';

/// One-time gate for the first-run locale + timezone dashboard banner.
///
/// The app never blocks the user with an onboarding screen; the device locale
/// and timezone are auto-detected and applied at launch. This gate only decides
/// whether the confirm-or-change banner has already been seen on THIS device:
/// it records that in [Vault] (secure storage) and mirrors the flag into an
/// in-memory field so the banner can read it synchronously (the widget build is
/// synchronous while [Vault] access is async).
///
/// The gate is loaded once at boot ([load]), then flipped by the banner when
/// the user confirms, chooses change, or dismisses ([markCompleted]). Once
/// completed the banner never shows again: the flag stays set across sessions.
class LocaleOnboardingGate {
  LocaleOnboardingGate._();

  /// The process-wide gate instance read by the first-run banner.
  static final LocaleOnboardingGate instance = LocaleOnboardingGate._();

  /// The [Vault] key under which the "banner seen" flag is persisted.
  static const String vaultKey = 'onboarding_locale_done';

  bool _completed = false;

  /// Whether the first-run locale banner has already been resolved on this
  /// device; reflects the value loaded at boot plus any in-session
  /// [markCompleted] call.
  bool get isCompleted => _completed;

  /// Loads the persisted flag from [Vault] into memory.
  ///
  /// Called once at boot after `Magic.init` (so the `vault` binding exists) and
  /// before the dashboard first renders. A missing key resolves to
  /// not-completed.
  Future<void> load() async {
    try {
      _completed = (await Vault.get(vaultKey)) != null;
    } catch (error) {
      // A vault read failure must not crash boot (every sibling boot hook is
      // non-throwing); default to not-completed so the banner still shows.
      _completed = false;
      if (Magic.bound('log')) {
        Log.warning('[LocaleOnboardingGate] vault read failed: $error');
      }
    }
  }

  /// Marks the first-run banner as resolved for this device.
  ///
  /// Flips the in-memory flag immediately (so the banner hides at once) and
  /// persists it to [Vault] so it survives restarts and re-logins.
  Future<void> markCompleted() async {
    _completed = true;
    await Vault.put(vaultKey, '1');
  }

  /// Resets the in-memory flag between tests.
  @visibleForTesting
  void resetForTesting({bool completed = false}) {
    _completed = completed;
  }
}
