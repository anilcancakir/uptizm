import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'usage_meter.dart';

/// Static variant-matrix preview for [UsageMeter].
///
/// Mirrors the design lab `UsageMeter.preview.tsx` (headroom, near-limit, at
/// limit, unlimited) plus an explicit degraded (>80%) row to cover every tone.
/// One preview class per file; discovered by `previews:refresh`.
class UsageMeterPreview extends StatelessWidget {
  /// Creates the UsageMeter variant-matrix preview.
  const UsageMeterPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return const WDiv(
      className: 'flex flex-col gap-5 p-6 max-w-sm',
      children: [
        UsageMeter(label: 'Monitors', used: 4, limit: 50),
        UsageMeter(label: 'Status pages', used: 2, limit: 3),
        UsageMeter(label: 'Alerts', used: 9, limit: 10),
        UsageMeter(label: 'Responders', used: 3, limit: 3),
        UsageMeter(label: 'Monitors', used: 420, limit: null),
      ],
    );
  }
}
