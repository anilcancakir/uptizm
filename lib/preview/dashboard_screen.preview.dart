import 'package:flutter/widgets.dart';

import '../resources/views/dashboard/dashboard_view.dart';
import 'preview_mock_harness.dart';
import 'screen_preview_scaffold.dart';

/// Screen preview for the [DashboardView].
///
/// Renders the dashboard home exactly as the routed `/` content: the KPI row,
/// active incidents, the monitor snippet, and the AI inbox, composed from the
/// design-lab mock fixtures. The view is a plain [StatelessWidget] reading the
/// fixtures directly (no controller, no network), so the harness installs the
/// success state only to seed a representative auth session for the catalog;
/// the page renders identically with or without it.
///
/// Rendered with [PreviewChrome.none]: the bare page body. The authenticated
/// app shell (sidebar / bottom nav) wraps this content at the routing layer,
/// not here, so the preview shows the page surface in isolation.
///
/// One public preview class per file is the canonical screen-preview contract;
/// the catalog index discovers `*.preview.dart` files directly.
class DashboardScreenPreview extends StatelessWidget {
  /// Creates the dashboard screen preview.
  const DashboardScreenPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return ScreenPreviewScaffold(
      state: PreviewState.success,
      builder: (_) => const DashboardView(),
    );
  }
}
