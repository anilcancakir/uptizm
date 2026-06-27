import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'notification_center.dart';

/// Static preview for [NotificationCenter].
///
/// Renders the panel with its self-contained sample feed (mixed read /
/// unread, all six feed kinds) so the catalog shows the full surface in light
/// and dark. One preview class per file is the canonical atomic-component
/// contract.
class NotificationCenterPreview extends StatelessWidget {
  /// Creates the notification-center preview.
  const NotificationCenterPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-4 p-6 items-start',
      children: [
        WText(
          'NotificationCenter: sample feed',
          className: 'text-sm font-semibold text-fg',
        ),
        NotificationCenter(
          onClose: () {},
          onItemTap: (_) {},
          onSettings: () {},
        ),
      ],
    );
  }
}
