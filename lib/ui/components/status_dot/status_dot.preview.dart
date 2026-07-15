import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/status_key.dart';
import 'status_dot.dart';

/// Static variant-matrix preview for [StatusDot].
///
/// Mirrors the React `StatusDot.preview.tsx` structure: each status rendered
/// at all three sizes with its label. One preview class per file; discovered
/// by `previews:refresh` via the `*.preview.dart` glob. Never imported from
/// production code.
class StatusDotPreview extends StatelessWidget {
  /// Creates the status dot variant-matrix preview.
  const StatusDotPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6',
      children: [
        for (final size in StatusDotSize.values)
          WDiv(
            className: 'flex flex-col gap-2',
            children: [
              WText(
                size.name,
                className:
                    'text-xs font-mono uppercase tracking-wide text-fg-muted',
              ),
              WDiv(
                className: 'wrap items-center gap-4',
                children: [
                  for (final status in StatusKey.values)
                    WDiv(
                      className: 'flex flex-row items-center gap-2',
                      children: [
                        StatusDot(status, size: size),
                        WText(status.name, className: 'text-sm text-fg-muted'),
                      ],
                    ),
                ],
              ),
            ],
          ),
      ],
    );
  }
}
