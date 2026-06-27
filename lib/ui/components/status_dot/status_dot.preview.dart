import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/status.dart';
import 'status_dot.dart';

/// Static variant-matrix preview for [StatusDot].
///
/// Renders every [StatusKey] in a row so the catalog shows the full surface
/// in light and dark. One preview class per file is the canonical atomic-
/// component contract.
class StatusDotPreview extends StatelessWidget {
  /// Creates the status dot variant-matrix preview.
  const StatusDotPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-3 p-6',
      children: [
        for (final status in StatusKey.values)
          WDiv(
            className: 'flex flex-row items-center gap-3',
            children: [
              StatusDot(status),
              WText(status.name, className: 'text-sm text-fg-muted'),
            ],
          ),
      ],
    );
  }
}
