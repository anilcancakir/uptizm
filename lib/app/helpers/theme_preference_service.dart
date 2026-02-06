import 'package:magic/magic.dart';

/// Service for managing theme preference persistence using Vault facade.
///
/// Stores user's dark/light mode preference to device storage and loads it
/// on app startup.
class ThemePreferenceService {
  /// Storage key for theme mode preference
  static const _key = 'theme_mode';

  /// Save theme preference to Vault.
  ///
  /// [isDark] - true for dark mode, false for light mode
  static Future<void> save(bool isDark) async {
    final value = isDark ? 'dark' : 'light';
    await Vault.put(_key, value);
    Log.info('Theme preference saved: $value');
  }

  /// Load theme preference from Vault.
  ///
  /// Returns:
  /// - true for dark mode
  /// - false for light mode
  /// - null if no preference is saved
  static Future<bool?> load() async {
    final saved = await Vault.get(_key);
    Log.info('Theme preference loaded: $saved');

    if (saved == null) {
      Log.info('No theme preference found, will use default');
      return null;
    }

    if (saved == 'dark') {
      return true;
    }

    if (saved == 'light') {
      return false;
    }

    // Invalid value in storage, treat as not set
    Log.warning('Invalid theme preference value: $saved');
    return null;
  }
}
