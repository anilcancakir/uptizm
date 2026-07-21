import 'package:flutter/foundation.dart' show debugPrint;
import 'package:flutter_timezone/flutter_timezone.dart';

import 'package:magic/magic.dart';

/// Resolves the device's raw timezone info; the seam that lets tests inject a
/// fake for `flutter_timezone`'s static [FlutterTimezone.getLocalTimezone]
/// without a real platform channel.
typedef TimezoneResolver = Future<TimezoneInfo> Function();

/// Detects the device's IANA timezone identifier (e.g. `Europe/Istanbul`).
///
/// Magic's own timezone detection (`DateManager.detectTimezone`) reads
/// `DateTime.now().timeZoneName`, which is a locale-dependent abbreviation
/// (e.g. `TRT`), not an IANA id, and falls back to offset-matching guesswork.
/// This service wraps `flutter_timezone`'s platform-native lookup, which
/// returns a real IANA identifier, so the caller can feed it straight into
/// `DateManager.setTimezone(...)`.
///
/// A detected value (including a bare `"UTC"`) is never treated as
/// authoritative here: browsers with fingerprinting resistance (Firefox/Tor)
/// always report `UTC` regardless of the real device timezone, so callers
/// must still let the user override it (the onboarding timezone picker is
/// that mitigation). This service only guarantees it never throws: a
/// platform-channel failure resolves to `null`, letting the caller fall back
/// to the configured default timezone.
class DeviceTimezoneService {
  /// Creates a detection service.
  ///
  /// [resolver] defaults to `FlutterTimezone.getLocalTimezone`; tests inject
  /// a fake to avoid depending on a real platform channel.
  DeviceTimezoneService({TimezoneResolver? resolver})
    : _resolver = resolver ?? FlutterTimezone.getLocalTimezone;

  final TimezoneResolver _resolver;

  /// Detects the device's IANA timezone identifier.
  ///
  /// Returns the identifier on success, or `null` if detection throws or
  /// resolves to a blank identifier. Never throws.
  Future<String?> detect() async {
    try {
      final TimezoneInfo info = await _resolver();
      final String identifier = info.identifier.trim();
      return identifier.isEmpty ? null : identifier;
    } catch (error) {
      _logWarning('timezone detection failed: $error');
      return null;
    }
  }

  /// Logs a warning gracefully, falling back to `debugPrint` when the `log`
  /// service is not bound (e.g. in tests that construct this service in
  /// isolation from `Magic.init`).
  void _logWarning(String message) {
    try {
      if (Magic.bound('log')) {
        Log.warning('[DeviceTimezoneService] $message');
      } else {
        debugPrint('[DeviceTimezoneService] $message');
      }
    } catch (_) {
      debugPrint('[DeviceTimezoneService] $message');
    }
  }
}
