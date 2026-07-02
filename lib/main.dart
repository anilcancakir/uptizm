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
import 'package:magic_devtools/magic_devtools.dart';
import 'package:magic_starter/magic_starter.dart'
    show
        MagicStarter,
        MagicStarterTheme,
        MagicStarterCardTheme,
        MagicStarterModalTheme;
import 'config/magic_starter.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Dev-tooling (dusk + telescope) plugins boot BEFORE Magic.init so the
  // snapshot pipeline and exception watcher are live during Magic boot. The
  // kDebugMode guard stays at the call site so release builds tree-shake the
  // whole branch. See MagicDevtools.installPre.
  if (kDebugMode) MagicDevtools.installPre();

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

  // Magic integrations wire magic's runtime into dusk + telescope AFTER
  // Magic.init, since their watchers/adapter/enrichers resolve through the IoC
  // container. See MagicDevtools.installPost.
  if (kDebugMode) MagicDevtools.installPost();

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

  // Point magic_starter's modal/bottom-sheet surfaces at uptizm tokens too. The
  // default modal theme is `dark:bg-gray-800` (a lighter, bluer slate than the
  // uptizm surface hierarchy), which made the metric create/edit + detail sheets
  // read as off-palette. Re-skin container/header/footer/title/description with
  // the same semantic Wind tokens the rest of the app uses.
  MagicStarter.useModalTheme(
    const MagicStarterModalTheme(
      containerClassName: 'bg-surface-container border border-color-border',
      headerClassName: 'px-6 pt-6 pb-4',
      bodyClassName: 'px-6 pb-4',
      footerClassName: 'px-6 py-4 border-t border-color-border',
      titleClassName: 'text-xl font-semibold text-fg mb-2',
      descriptionClassName: 'text-sm text-fg-muted',
      primaryButtonClassName:
          'px-4 py-2 rounded-lg bg-primary text-on-primary text-sm font-medium',
      secondaryButtonClassName:
          'px-4 py-2 rounded-lg bg-surface-container border '
          'border-color-border text-fg text-sm font-medium',
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
