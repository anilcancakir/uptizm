import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'config/app.dart';
import 'config/routing.dart';
import 'config/view.dart';
import 'config/auth.dart';
import 'config/database.dart';
import 'config/network.dart';
import 'config/cache.dart';
import 'config/logging.dart';
import 'config/broadcasting.dart';
import 'config/deeplink.dart';
import 'config/wind_theme.g.dart';
import 'config/uptizm_status_tokens.dart';
import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:fluttersdk_dusk/dusk.dart';
import 'package:magic_devtools/dusk.dart';
import 'package:fluttersdk_telescope/telescope.dart';
import 'package:magic_devtools/telescope.dart';
import 'config/magic_starter.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  if (kDebugMode) {
    DuskPlugin.install();
  }
  if (kDebugMode) {
    TelescopePlugin.install();
    TelescopePlugin.registerWatcher(ExceptionWatcher());
    TelescopePlugin.registerWatcher(DumpWatcher());
  }
  await Magic.init(
    configFactories: [
      () => appConfig,
      () => routingConfig,
      () => viewConfig,
      () => authConfig,
      () => databaseConfig,
      () => networkConfig,
      () => cacheConfig,
      () => loggingConfig,
      () => broadcastingConfig,
      () => deeplinkConfig,
      () => magicStarterConfig,
    ],
  );
  if (kDebugMode) {
    MagicTelescopeIntegration.install();
  }
  if (kDebugMode) {
    MagicDuskIntegration.install();
  }
  // Theme generated from DESIGN.md via `design:sync` (the 17 standard semantic
  // roles), merged with the hand-authored monitoring status families
  // (up/down/degraded/paused/info/ai) that design:sync never emits. Regenerate
  // the generated half with: dart run bin/dispatcher.dart design:sync
  final windTheme = WindThemeData(
    colors: designColors,
    aliases: {...designAliases, ...uptizmStatusAliases},
  );

  runApp(MagicApplication(title: 'Uptizm', windTheme: windTheme));
}
