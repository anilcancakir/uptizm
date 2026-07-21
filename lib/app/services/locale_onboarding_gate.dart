import 'package:flutter/foundation.dart' show visibleForTesting;

import 'package:magic/magic.dart';

/// One-time gate for the post-register locale + timezone onboarding screen.
///
/// Register and login BOTH land on the shared `MagicStarterConfig.homeRoute()`
/// (there is no register-only navigation seam), so the onboarding hop cannot be
/// keyed off the auth action itself. Instead this gate records whether the
/// onboarding has been seen on THIS device in [Vault] (secure storage) and
/// mirrors that flag into an in-memory field so the routing middleware can read
/// it synchronously (the router's redirect callback is synchronous, while
/// [Vault] access is async).
///
/// The gate is loaded once at boot ([load]) before the router first evaluates a
/// redirect, then flipped by the onboarding screen on confirm or skip
/// ([markCompleted]). Once completed, a later login of an already-onboarded user
/// routes straight to the dashboard: the flag stays set across sessions.
class LocaleOnboardingGate {
  LocaleOnboardingGate._();

  /// The process-wide gate instance read by the routing middleware.
  static final LocaleOnboardingGate instance = LocaleOnboardingGate._();

  /// The [Vault] key under which the "onboarding seen" flag is persisted.
  static const String vaultKey = 'onboarding_locale_done';

  bool _completed = false;

  /// Whether locale onboarding has already been completed on this device.
  ///
  /// Read synchronously by [RedirectToLocaleOnboarding]; reflects the value
  /// loaded at boot plus any in-session [markCompleted] call.
  bool get isCompleted => _completed;

  /// Loads the persisted flag from [Vault] into memory.
  ///
  /// Called once at boot after `Magic.init` (so the `vault` binding exists) and
  /// before the router first evaluates a redirect. A missing key resolves to
  /// not-completed.
  Future<void> load() async {
    try {
      _completed = (await Vault.get(vaultKey)) != null;
    } catch (error) {
      // A vault read failure must not crash boot (every sibling boot hook is
      // non-throwing); default to not-completed so onboarding still shows.
      _completed = false;
      if (Magic.bound('log')) {
        Log.warning('[LocaleOnboardingGate] vault read failed: $error');
      }
    }
  }

  /// Marks onboarding as completed for this device.
  ///
  /// Flips the in-memory flag immediately (so the subsequent home navigation is
  /// no longer intercepted) and persists it to [Vault] so it survives restarts
  /// and re-logins.
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
