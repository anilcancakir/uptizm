import 'package:flutter/material.dart';
import 'package:flutter_web_plugins/url_strategy.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';

import 'app/helpers/theme_preference_service.dart';
import 'config/app.dart';
import 'config/auth.dart';
import 'config/network.dart';
import 'config/social_auth.dart';
import 'config/view.dart';
import 'config/notifications.dart';

void main() async {
  usePathUrlStrategy();
  WidgetsFlutterBinding.ensureInitialized();

  // Initialize Magic
  await Magic.init(
    configFactories: [
      () => appConfig,
      () => authConfig,
      () => networkConfig,
      () => socialAuthConfig,
      () => viewConfig,
      () => notificationConfig,
    ],
  );

  // Load saved theme preference
  final savedDark = await ThemePreferenceService.load();

  // Determine theme behavior:
  // - If user has saved preference → use it and disable system sync
  // - If no saved preference → use system default and keep syncing
  final hasPreference = savedDark != null;
  final brightness = hasPreference
      ? (savedDark ? Brightness.dark : Brightness.light)
      : WidgetsBinding.instance.platformDispatcher.platformBrightness;

  // Create WindThemeData with saved brightness preference
  final windTheme = WindThemeData(
    brightness: brightness,
    syncWithSystem:
        !hasPreference, // Sync with system only if no preference saved
    colors: {
      'primary': MaterialColor(0xFF009E60, <int, Color>{
        50: Color(0xFFCEFFE0),
        100: Color(0xFF73FFB4),
        200: Color(0xFF00EC92),
        300: Color(0xFF00D080),
        400: Color(0xFF00B870),
        500: Color(0xFF009E60),
        600: Color(0xFF007D4B),
        700: Color(0xFF005D36),
        800: Color(0xFF004024),
        900: Color(0xFF002312),
      }),
    },
  );

  runApp(
    MagicApplication(
      title: 'Uptizm',
      windTheme: windTheme,
      onInit: () {
        Log.info('Uptizm App initialized!');
      },
    ),
  );
}
