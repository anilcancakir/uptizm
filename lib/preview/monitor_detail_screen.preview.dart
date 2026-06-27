import 'package:flutter/widgets.dart';

import '../resources/views/monitor_detail_view.dart';
import 'preview_mock_harness.dart';
import 'screen_preview_scaffold.dart';

/// Screen preview for the [MonitorDetailView].
///
/// Renders the monitor detail screen exactly as the routed `/monitors/:id`
/// content for a known mock id (`'api'`, the degraded monitor that carries a
/// response series, custom metrics, and an AI-analyzed incident, so every
/// surface of the screen, the KPI row, the 90-day uptime bar, the response
/// MetricChart, the CheckHistoryTable, and the AiAnalysisCard, is exercised).
///
/// The view is a plain [StatefulWidget] reading the design-lab fixtures
/// directly (no controller, no network), so the harness installs the success
/// state only to seed a representative auth session for the catalog; the page
/// renders identically with or without it.
///
/// Rendered with [PreviewChrome.none]: the bare page body. The authenticated
/// app shell (sidebar / bottom nav) wraps this content at the routing layer,
/// not here, so the preview shows the page surface in isolation.
///
/// One public preview class per file is the canonical screen-preview contract;
/// the catalog index discovers `*.preview.dart` files directly.
class MonitorDetailScreenPreview extends StatelessWidget {
  /// Creates the monitor detail screen preview.
  const MonitorDetailScreenPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return ScreenPreviewScaffold(
      state: PreviewState.success,
      builder: (_) => const MonitorDetailView(id: 'api'),
    );
  }
}
