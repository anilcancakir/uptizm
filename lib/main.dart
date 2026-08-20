import 'package:flutter/material.dart';
import 'package:magic/magic.dart';
import 'app/services/locale_onboarding_gate.dart';
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
import 'config/page_header_theme.dart';
import 'config/uptizm_status_tokens.dart';
import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:magic_devtools/magic_devtools.dart';
import 'package:sentry_flutter/sentry_flutter.dart';
import 'config/sentry.dart';
import 'package:magic_starter/magic_starter.dart'
    show MagicStarter, MagicStarterCardTheme, MagicStarterModalTheme;
import 'config/magic_starter.dart';

void main() async {
  WidgetsFlutterBinding.ensureInitialized();

  // Load `.env` BEFORE Sentry, because the DSN lives in it and Magic.init is
  // otherwise the first thing to read the file. `Env.load` is idempotent (it
  // returns early once loaded), so Magic.init's own call below is a no-op and
  // this costs nothing.
  await Env.load();

  // Everything the app does happens inside `appRunner`, deliberately: a failure
  // during Magic.init or a provider's boot is exactly the kind that ships a
  // blank page to a customer, and it would be invisible if Sentry only started
  // afterwards.
  //
  // With no DSN this call still runs `appRunner` and simply reports nothing,
  // which is what every development machine and the whole test suite do. See
  // lib/config/sentry.dart.
  await SentryFlutter.init(configureSentry, appRunner: _boot);
}

/// Boot the framework and hand the app to Flutter.
Future<void> _boot() async {
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

  // Load the one-time locale-onboarding flag from the vault into memory BEFORE
  // the router first evaluates a redirect: the onboarding middleware reads it
  // synchronously, so it must be resolved by boot to avoid intercepting an
  // already-onboarded user on the first navigation.
  await LocaleOnboardingGate.instance.load();

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
      // The starter default is `p-6 gap-4` at every width. On a phone two KPI
      // cards sit side by side inside a 402pt screen, so 24pt of padding per
      // card spends 96pt of the row on air and pushes a four-card grid most of
      // a viewport tall. 16pt on a phone, the original 24 from `lg`.
      paddingClassName: 'p-4 gap-3 lg:p-6 lg:gap-4',
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

  // Page headers: a phone gets one tight row, a desktop keeps the roomier one.
  // The values, and why they differ per breakpoint, live in
  // lib/config/page_header_theme.dart so a widget test can measure them.
  MagicStarter.usePageHeaderTheme(uptizmPageHeaderTheme);

  runApp(
    MagicApplication(
      title: Config.get<String>('app.name', 'Uptizm')!,
      windTheme: windTheme,
    ),
  );
}
