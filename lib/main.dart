import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'app/services/locale_application_service.dart';
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
import 'config/localization.dart';
import 'config/wind_theme.g.dart';
import 'config/uptizm_status_tokens.dart';
import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:magic_devtools/magic_devtools.dart';
import 'package:magic_starter/magic_starter.dart'
    show MagicStarter, MagicStarterCardTheme, MagicStarterModalTheme;
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
      () => localizationConfig,
      () => magicStarterConfig,
    ],
  );

  // Boot DateManager and override its timezone with the device's real IANA
  // id (magic's own detection reads `DateTime.timeZoneName`, an
  // abbreviation, not an IANA identifier), so the `X-Timezone` header magic
  // sends on every request carries a value the backend can actually resolve.
  await LocaleApplicationService().applyDetectedTimezone();

  // Magic integrations wire magic's runtime into dusk + telescope AFTER
  // Magic.init, since their watchers/adapter/enrichers resolve through the IoC
  // container. See MagicDevtools.installPost.
  if (kDebugMode) MagicDevtools.installPost();

  // Theme generated from DESIGN.md via `design:sync` (the 17 standard semantic
  // roles), merged with the hand-authored monitoring status families
  // (up/down/degraded/paused/info/ai) that design:sync never emits. Regenerate
  // the generated half with: dart run bin/dispatcher.dart design:sync
  final windTheme = WindThemeData(
    colors: designColors,
    aliases: {...designAliases, ...uptizmStatusAliases},
  );

  // Adopt the whole uptizm palette across all 7 magic_starter sub-themes in one
  // call (MS-7a). This derives navigation, form, auth, page-header, and layout
  // surfaces from uptizm's semantic tokens instead of magic_starter's default
  // `dark:bg-gray-800` gray palette. The two overrides below layer uptizm's
  // deliberate refinements on top (useWindTheme is additive; later setters win).
  MagicStarter.useWindTheme(windTheme);

  // Cards sit on `surface-container` (not the base `surface`) so the reused
  // Card (KPI / stat cards) reads as a raised panel over the page. Tonal
  // hierarchy only (no drop shadows), per DESIGN.md.
  MagicStarter.useCardTheme(
    const MagicStarterCardTheme(
      surfaceClassName: 'bg-surface-container border border-color-border',
      elevatedClassName: 'bg-surface-container border border-color-border',
      insetClassName:
          'bg-surface-container-high border border-color-border-subtle',
      titleClassName: 'text-lg font-semibold text-fg',
    ),
  );

  // Modal/bottom-sheet surfaces: uptizm uses a hairline top-border footer (no
  // tonal footer fill) and its own primary/secondary button tokens.
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

  runApp(
    MagicApplication(
      title: Config.get<String>('app.name', 'Uptizm')!,
      windTheme: windTheme,
    ),
  );
}
