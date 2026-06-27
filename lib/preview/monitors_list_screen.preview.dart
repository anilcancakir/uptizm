import 'package:flutter/widgets.dart';

import '../resources/views/monitors_list_view.dart';
import 'preview_mock_harness.dart';
import 'screen_preview_scaffold.dart';

/// Screen preview for the [MonitorsListView].
///
/// Renders the monitors list exactly as the routed `/monitors` content: the
/// page header, status filter, and the full monitor fixture list composed from
/// the design-lab mock fixtures. The view is a plain [StatefulWidget] reading
/// the fixtures directly (no controller, no network), so the harness installs
/// the success state only to seed a representative auth session for the
/// catalog; the page renders identically with or without it.
///
/// Rendered with [PreviewChrome.none]: the bare page body. The authenticated
/// app shell (sidebar / bottom nav) wraps this content at the routing layer,
/// not here, so the preview shows the page surface in isolation.
///
/// One public preview class per file is the canonical screen-preview contract;
/// the catalog index discovers `*.preview.dart` files directly.
class MonitorsListScreenPreview extends StatelessWidget {
  /// Creates the monitors list screen preview.
  const MonitorsListScreenPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return ScreenPreviewScaffold(
      state: PreviewState.success,
      builder: (_) => const MonitorsListView(),
    );
  }
}
