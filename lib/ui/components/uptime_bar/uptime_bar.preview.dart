import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import 'uptime_bar.dart';

/// Static variant-matrix preview for [UptimeBar].
///
/// Renders three representative bars (all-up, mixed with incidents, with-outage)
/// at each size so the catalog shows the full surface in light and dark. One
/// preview class per file is the canonical atomic-component contract.
class UptimeBarPreview extends StatelessWidget {
  /// Creates the uptime bar variant-matrix preview.
  const UptimeBarPreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        // All-up: every segment is operational.
        _Section(
          label: 'all-up · 30 days · sm',
          child: UptimeBar(
            segments: uptime90().take(30).toList(),
            size: UptimeBarSize.sm,
            uptimePercent: '100.00%',
          ),
        ),

        // Mixed: a realistic 90-day spread with degraded and one outage.
        _Section(
          label: 'mixed · 90 days · md',
          child: UptimeBar(
            segments: uptime90(down: [41, 42], degraded: [22, 58, 73, 74]),
            size: UptimeBarSize.md,
            uptimePercent: '99.78%',
          ),
        ),

        // With-outage: recent outage is visually prominent in the trailing days.
        _Section(
          label: 'with-outage · 90 days · lg',
          child: UptimeBar(
            segments: uptime90(down: [0, 1, 2], degraded: [3, 4]),
            size: UptimeBarSize.lg,
            uptimePercent: '96.67%',
          ),
        ),

        // All sizes at a glance — no label.
        for (final size in UptimeBarSize.values)
          _Section(
            label: '${size.name} · no label',
            child: UptimeBar(
              segments: uptime90(down: [10], degraded: [30, 60]),
              size: size,
            ),
          ),
      ],
    );
  }
}

/// Internal preview section wrapper — label + child row.
class _Section extends StatelessWidget {
  final String label;
  final Widget child;

  const _Section({required this.label, required this.child});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(label, className: 'font-mono text-xs text-fg-muted'),
        child,
      ],
    );
  }
}
