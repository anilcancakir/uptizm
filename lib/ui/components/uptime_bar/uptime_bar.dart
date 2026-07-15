import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/support/monitor_types.dart' show UptimeSegment;
import '../../../app/mocks/monitors.dart';
import 'uptime_bar.recipe.dart';

/// **The 90-Day Uptime Timeline Bar**
///
/// Renders a horizontal row of equal-width rounded segments, each colored by
/// the status token of the corresponding [UptimeSegment]. An optional
/// [uptimePercent] label appears below the track in Geist Mono tabular-nums.
///
/// Uses [uptimeBarRecipe] for all styling; no raw hex, `Color(0xFF...)`, or
/// `Colors.*` anywhere.
///
/// ### Example Usage:
///
/// ```dart
/// // 90-day bar — all up
/// UptimeBar(segments: uptime90())
///
/// // 90-day bar with incidents, compact height, with label
/// UptimeBar(
///   segments: uptime90(down: [0], degraded: [3, 4]),
///   size: UptimeBarSize.sm,
///   uptimePercent: '99.94%',
/// )
/// ```
@immutable
class UptimeBar extends StatelessWidget {
  /// The ordered list of daily status buckets.
  ///
  /// Typically built with [uptime90].
  final List<UptimeSegment> segments;

  /// Visual height of the bar track; defaults to [UptimeBarSize.md].
  final UptimeBarSize size;

  /// Optional trailing uptime percentage string (e.g. `"99.94%"`).
  ///
  /// When non-null it is rendered in Geist Mono below the bar with
  /// `tabular-nums` so digits align across stacked rows.
  final String? uptimePercent;

  /// Creates an [UptimeBar] for the given segment list.
  const UptimeBar({
    super.key,
    required this.segments,
    this.size = UptimeBarSize.md,
    this.uptimePercent,
  });

  @override
  Widget build(BuildContext context) {
    // 1. Resolve track className via size variant only; the segment slot is
    //    resolved per-segment inside _buildSegments().
    final classes = uptimeBarRecipe(variants: {kUptimeBarSizeAxis: size.name});

    // 2. Build the horizontal segment row.
    final track = WDiv(className: classes['track'], children: _buildSegments());

    // 3. When no label is needed, return the bare track.
    if (uptimePercent == null) return track;

    // 4. Wrap in a column with the percentage label below the track.
    return WDiv(
      className: 'flex flex-col gap-1',
      children: [
        track,
        WText(uptimePercent!, className: classes['label']),
      ],
    );
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  List<Widget> _buildSegments() {
    return [
      for (final segment in segments)
        WDiv(className: uptimeBarSegmentClassName(segment.status.name)),
    ];
  }
}

/// Visual height variants for [UptimeBar].
///
/// - [sm] — compact (h-6); suitable for dense monitor lists.
/// - [md] — standard (h-9); the default; fits most contexts.
/// - [lg] — prominent (h-12); for monitor-detail headers.
enum UptimeBarSize {
  /// Compact height (h-6); good for dense rows.
  sm,

  /// Standard height (h-9); the default.
  md,

  /// Prominent height (h-12); for detail headers.
  lg,
}
