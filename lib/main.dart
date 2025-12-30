import 'package:flutter/material.dart';
import 'package:fluttersdk_magic/fluttersdk_magic.dart';
import 'package:flutter_web_plugins/url_strategy.dart';

import 'config/app.dart';
import 'config/auth.dart';
import 'config/database.dart';
import 'config/logging.dart';
import 'config/network.dart';

void main() async {
  usePathUrlStrategy();
  WidgetsFlutterBinding.ensureInitialized();

  final windTheme = WindThemeData(
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

  // Initialize Magic
  await Magic.init(
    configFactories: [
      () => appConfig,
      () => databaseConfig,
      () => loggingConfig,
      () => authConfig,
      () => networkConfig,
    ],
  );

  runApp(
    MagicApplication(
      title: 'Uptizm',
      windTheme: windTheme,
      debugShowCheckedModeBanner: true,
      onInit: () {
        Log.info('Uptizm App initialized!');
      },
    ),
  );
}
