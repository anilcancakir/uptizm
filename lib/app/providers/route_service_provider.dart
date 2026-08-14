import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:magic/magic.dart';
import 'package:magic_devtools/preview.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:sentry_flutter/sentry_flutter.dart';

import '../kernel.dart';
import '../../routes/app.dart';
import '../../_previews.g.dart';
import 'package:magic_starter/previews.dart' as starter_previews;

/// Route Service Provider.
///
/// Registers the HTTP kernel, the Magic Starter route groups (auth, profile,
/// teams, notifications) and the uptizm application routes. The starter groups
/// own the account surface (login/register, settings hub + sub-pages, team
/// create/settings/invitations, notification preferences); the uptizm shell
/// carries the `'auth'` gate, so an unauthenticated boot redirects to the
/// starter login route.
class RouteServiceProvider extends ServiceProvider {
  RouteServiceProvider(super.app);

  @override
  void register() {
    // Register middleware kernel — runs synchronously during bootstrap.
    registerKernel();
  }

  @override
  Future<void> boot() async {
    // Name each screen in Sentry, and time how long it took to get there.
    //
    // Registered in boot() for the same reason the preview catalog below is:
    // MagicRouter refuses an observer once it has built its routerConfig, and
    // that build happens on the first frame. It also has to land before the
    // first navigation, since a transaction can only measure a route change it
    // was present for.
    //
    // Without this every event is filed against a route this app never names,
    // because magic drives go_router and the SDK cannot see through it. Inert
    // without a DSN, like the rest of the SDK.
    MagicRouter.instance.addObserver(SentryNavigatorObserver());

    // Register the Magic Starter account surface FIRST: auth pages (carrying
    // the 'guest' gate), the settings hub + sub-pages, team management and
    // notification preferences. These own the paths uptizm previously served
    // itself; registering them here hands that surface to the starter and lets
    // the uptizm shell gate ('auth') redirect an unauthenticated boot to the
    // starter login route.
    registerMagicStarterAuthRoutes();
    registerMagicStarterProfileRoutes();
    registerMagicStarterTeamRoutes();
    registerMagicStarterNotificationRoutes();

    // Register application route definitions.
    registerAppRoutes();

    // Dev-only component preview catalog. Registered here, in boot(), so it
    // lands BEFORE MagicRouter first builds its routerConfig (the router locks
    // its route table on first access). The kDebugMode guard lets the optimizer
    // prove the whole catalog dead in release; registerRoutes() folds itself
    // out behind kReleaseMode + PREVIEW_ENABLED as a second line of defence.
    if (kDebugMode) {
      // App-owned previews (foundations, feature screens, nav shells) plus the
      // full magic_starter component set, surfaced through its dev-only
      // `previews.dart` barrel (no duplication). Sorted by label for an
      // idea-design-style alphabetical catalog. The whole block is kDebugMode-
      // gated, so the starter preview set is tree-shaken from release.
      final List<PreviewEntry> entries = <PreviewEntry>[
        ...previewEntries(),
        for (final p in starter_previews.starterComponentPreviews())
          PreviewEntry(label: p.label, slug: p.slug, builder: p.builder),
      ]..sort((a, b) => a.label.compareTo(b.label));
      MagicPreview.register(entries);
      MagicPreview.registerRoutes();
    }
  }
}
