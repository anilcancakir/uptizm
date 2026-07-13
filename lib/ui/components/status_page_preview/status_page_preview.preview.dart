import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status_pages.dart';
import '../../../app/models/status_page.dart';
import 'status_page_preview.dart';

/// Static variant-matrix preview for [StatusPagePreview].
///
/// Renders three configs so the catalog shows the full range: a synthesized
/// all-healthy page (operational banner, subscriptions on), the `acme` fixture
/// (an outage banner with a live-metrics grid, past incidents, and the
/// subscribe box), and the `internal` fixture (subscriptions off). One public
/// preview class per file is the discovery contract `previews:refresh` enforces.
class StatusPagePreviewPreview extends StatelessWidget {
  /// Creates the StatusPagePreview variant-matrix preview.
  const StatusPagePreviewPreview({super.key});

  @override
  Widget build(BuildContext context) {
    // A healthy variant: reuse the customer-facing fixture but assign only the
    // operational marketing monitor so the overall banner reads "All systems
    // operational" and no past incidents surface.
    final StatusPage healthy = cloneStatusPage(
      statusPages.first,
      name: 'Acme Status (healthy)',
      monitorIds: const ['marketing'],
      metricKeys: const ['marketing.dom_load'],
    );

    return WDiv(
      className: 'flex flex-col gap-8 p-6',
      children: [
        _labelled('Healthy — operational, subscriptions on', healthy),
        _labelled(
          'Outage — live metrics, incidents, subscriptions on',
          statusPages.first,
        ),
        _labelled(
          'Internal ops — subscriptions off',
          statusPages.last,
        ),
      ],
    );
  }

  /// One labelled preview block: a caption above the rendered page.
  Widget _labelled(String caption, StatusPage config) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(caption, className: 'text-xs text-fg-muted'),
        StatusPagePreview(config: config),
      ],
    );
  }
}
