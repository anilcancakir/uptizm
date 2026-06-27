import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_badge.dart';

/// Static variant-matrix preview for [StatusBadge].
///
/// Renders every [StatusKey] in a row so the catalog shows the full surface
/// in light and dark. One preview class per file is the canonical atomic-
/// component contract.
class StatusBadgePreview extends StatelessWidget {
  /// Creates the status badge variant-matrix preview.
  const StatusBadgePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-3 p-6',
      children: [
        for (final status in StatusKey.values)
          WDiv(
            className: 'flex flex-row items-center gap-3',
            children: [
              StatusBadge(status),
              WText(status.name, className: 'text-sm text-fg-muted'),
            ],
          ),
      ],
    );
  }
}
