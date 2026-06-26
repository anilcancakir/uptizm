import 'package:flutter/foundation.dart' show kDebugMode;
import 'package:magic/magic.dart';
import 'package:magic_devtools/preview.dart';

import '../kernel.dart';
import '../../routes/app.dart';
import '../../preview/_previews.g.dart';
import 'package:magic_starter/magic_starter.dart';
import 'package:magic_starter/previews.dart' as starter_previews;

/// Route Service Provider.
///
/// Registers the HTTP kernel and application routes.
class RouteServiceProvider extends ServiceProvider {
  RouteServiceProvider(super.app);

  @override
  void register() {
    // Register middleware kernel — runs synchronously during bootstrap.
    registerKernel();
  }

  @override
  Future<void> boot() async {
    // Register application route definitions.
    registerMagicStarterAuthRoutes();
    registerMagicStarterProfileRoutes();
    registerMagicStarterTeamRoutes();
    registerMagicStarterNotificationRoutes();
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
