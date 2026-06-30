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
import 'package:magic_starter/magic_starter.dart'
    show MagicStarter, MagicStarterTheme, MagicStarterCardTheme;
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
  // Point magic_starter's Card surfaces at uptizm's semantic tokens. Without
  // this the reused Card (KPI / stat cards) keeps magic_starter's default
  // `dark:bg-gray-800` fill, a lighter, bluer slate than the uptizm surface
  // hierarchy. Tonal hierarchy only (no drop shadows), per DESIGN.md.
  MagicStarter.useTheme(
    const MagicStarterTheme(
      card: MagicStarterCardTheme(
        surfaceClassName: 'bg-surface-container border border-color-border',
        elevatedClassName: 'bg-surface-container border border-color-border',
        insetClassName:
            'bg-surface-container-high border border-color-border-subtle',
      ),
    ),
  );
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
