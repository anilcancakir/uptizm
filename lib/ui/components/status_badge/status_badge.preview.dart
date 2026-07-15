import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/enums/status_key.dart';
import 'status_badge.dart';

/// Static variant-matrix preview for [StatusBadge].
///
/// Mirrors the React `StatusBadge.preview.tsx` structure:
/// - `sm` section: all six statuses at the compact size.
/// - `md` section: all six statuses at the default size.
/// - `no dot / custom label` section: dotless + custom-label variants.
///
/// One preview class per file; discovered by `previews:refresh` via the
/// `*.preview.dart` glob. Never imported from production code.
class StatusBadgePreview extends StatelessWidget {
  /// Creates the status badge variant-matrix preview.
  const StatusBadgePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        // Size sm — all statuses.
        _SizeSection(label: 'sm', size: StatusBadgeSize.sm),

        // Size md — all statuses.
        _SizeSection(label: 'md', size: StatusBadgeSize.md),

        // Dotless + custom label variants.
        WDiv(
          className: 'flex flex-col gap-2',
          children: [
            WText(
              'no dot · custom label',
              className:
                  'text-xs font-mono uppercase tracking-wide text-fg-muted',
            ),
            WDiv(
              className: 'wrap items-center gap-3',
              children: const [
                StatusBadge(
                  StatusKey.up,
                  showDot: false,
                  label: '99.98% uptime',
                ),
                StatusBadge(
                  StatusKey.down,
                  showDot: false,
                  label: '3 monitors down',
                ),
                StatusBadge(StatusKey.ai, label: 'Anomaly detected'),
              ],
            ),
          ],
        ),
      ],
    );
  }
}

/// A labeled section rendering [StatusBadge] for every [StatusKey] at [size].
class _SizeSection extends StatelessWidget {
  final String label;
  final StatusBadgeSize size;

  const _SizeSection({required this.label, required this.size});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(
          label,
          className: 'text-xs font-mono uppercase tracking-wide text-fg-muted',
        ),
        WDiv(
          className: 'wrap items-center gap-3',
          children: [
            for (final status in StatusKey.values)
              StatusBadge(status, size: size),
          ],
        ),
      ],
    );
  }
}
