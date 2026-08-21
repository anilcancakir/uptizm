import 'dart:async';
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
import 'config/uptizm_status_tokens.dart';
import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:magic_devtools/magic_devtools.dart';
import 'package:sentry_flutter/sentry_flutter.dart';
import 'config/sentry.dart';
import 'package:magic_starter/magic_starter.dart'
    show MagicStarter, MagicStarterCardTheme, MagicStarterModalTheme;
import 'config/magic_starter.dart';

void main() {
  // ONE zone for the binding and for `runApp`, which is the whole reason this
  // wrapper exists rather than the three statements below sitting bare in
  // `main`.
  //
  // `SentryFlutter.init` opens its own `runZonedGuarded` when it is called from
  // the root zone AND `PlatformDispatcher.onError` is unavailable, which is the
  // case on web (flutter#100277). The binding, meanwhile, has to be initialized
  // before `Env.load` reads the bundled `.env`, because `flutter_dotenv` goes
  // through `rootBundle` and swallows the failure into "using default values" if
  // it cannot. So the binding was initialized in the root zone while `runApp`
  // ran in Sentry's, and Flutter said so on every boot: "Zone mismatch. The
  // Flutter bindings were initialized in a different zone than is now being
  // used." Zone-scoped configuration then applies by whichever zone happened to
  // be current when a callback was registered, and on web that zone handler is
  // the ONLY uncaught-async-error path Sentry has.
  //
  // Entering a zone here makes `Sentry.runtimeChecker.isRootZone` false, so
  // `init` calls `appRunner` in this zone instead of a new one, and the error
  // handler below is the one Sentry would have installed itself.
  runZonedGuarded(
    () async {
      WidgetsFlutterBinding.ensureInitialized();

      // Load `.env` BEFORE Sentry, because the DSN lives in it and Magic.init is
      // otherwise the first thing to read the file. `Env.load` is idempotent (it
      // returns early once loaded), so Magic.init's own call below is a no-op and
      // this costs nothing.
      await Env.load();

      // Everything the app does happens inside `appRunner`, deliberately: a
      // failure during Magic.init or a provider's boot is exactly the kind that
      // ships a blank page to a customer, and it would be invisible if Sentry
      // only started afterwards.
      //
      // With no DSN this call still runs `appRunner` and simply reports nothing,
      // which is what every development machine and the whole test suite do. See
      // lib/config/sentry.dart.
      await SentryFlutter.init(configureSentry, appRunner: _boot);
    },
    (Object error, StackTrace stackTrace) async {
      // Both halves of what Sentry's own zone handler does: report it, then dump
      // it to the console. Without the dump an uncaught async error would vanish
      // from the local console the moment this wrapper took the zone over.
      await Sentry.captureException(error, stackTrace: stackTrace);

      FlutterError.dumpErrorToConsole(
        FlutterErrorDetails(exception: error, stack: stackTrace),
        forceReport: true,
      );
    },
  );
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
