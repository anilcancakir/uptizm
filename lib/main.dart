import 'package:flutter/material.dart';
import 'package:magic/magic.dart';

import 'config/app.dart';
import 'config/app_theme.dart';
import 'config/auth.dart';
import 'config/network.dart';
import 'config/social_auth.dart';
import 'config/view.dart';
import 'config/notifications.dart';
import 'config/deeplink.dart';
import 'config/routing.dart';
import 'config/cache.dart';
import 'config/database.dart';
import 'config/logging.dart';

import 'config/magic_starter.dart';

void main() async {
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
      () => deeplinkConfig,
      () => routingConfig,
      () => cacheConfig,
      () => databaseConfig,
      () => loggingConfig,
      () => magicStarterConfig,
    ],
  );

  runApp(
    MagicApplication(
      title: 'Uptizm',
      windTheme: AppTheme.windThemeData,
      onInit: () {
        Log.info('Uptizm App initialized!');
      },
    ),
  );
}
