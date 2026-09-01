import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'notification_center.dart';

/// Static preview for [NotificationCenter].
///
/// Renders all six feed kinds side by side, each captioned, so the catalog
/// shows every tint / dot pair in light and dark. One preview class per file is
/// the canonical atomic-component contract.
class NotificationCenterPreview extends StatelessWidget {
  /// Creates the notification-centre indicator preview.
  const NotificationCenterPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-start',
      children: [
        WText(
          'NotificationCenter: every feed kind',
          className: 'text-sm font-semibold text-fg',
        ),
        WDiv(
          className: 'flex flex-row wrap items-center gap-6',
          children: [
            for (final AppNotificationKind kind in AppNotificationKind.values)
              WDiv(
                className: 'flex flex-col items-center gap-2',
                children: [
                  NotificationCenter(kind: kind),
                  WText(kind.name, className: 'text-xs text-fg-muted'),
                ],
              ),
          ],
        ),
      ],
    );
  }
}
