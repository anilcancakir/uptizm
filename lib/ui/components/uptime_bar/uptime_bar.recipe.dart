import 'package:magic/magic.dart';

/// The size axis key for the uptime bar recipe.
const String kUptimeBarSizeAxis = 'size';

/// The status axis key for the uptime bar segment slot.
const String kUptimeBarStatusAxis = 'status';

/// Builds the slot [WindSlotRecipe] for [UptimeBar].
///
/// Slots:
/// - `track` — the outer full-width row that lays out the segment list.
/// - `segment` — each individual colored bucket segment.
/// - `label` — the trailing uptime percentage label (tabular-nums, Geist Mono).
///
/// Variants:
/// - `size` (on `track`): `sm` (h-6), `md` (h-9), `lg` (h-12).
/// - `status` (on `segment`): maps each [StatusKey] to its solid `bg-*` token.
///
/// Emission order: `base ++ size-variant ++ status-variant ++ compound ++ caller`.
///
/// ```dart
/// final classes = uptimeBarRecipe(variants: {kUptimeBarSizeAxis: 'md'});
/// WDiv(className: classes['track']);
/// ```
Map<String, String> uptimeBarRecipe({
  Map<String, String?>? variants,
  Map<String, String>? classNames,
}) {
  const recipe = WindSlotRecipe(
    slots: {
      'track': 'flex flex-row w-full items-stretch gap-[1px]',
      'segment': 'flex-1 min-w-0 rounded-sm',
      'label': 'tabular-nums font-mono text-xs text-fg-muted',
    },
    variants: {
      kUptimeBarSizeAxis: {
        'sm': {'track': 'h-6'},
        'md': {'track': 'h-9'},
        'lg': {'track': 'h-12'},
      },
      kUptimeBarStatusAxis: {
        'up': {'segment': 'bg-up'},
        'down': {'segment': 'bg-down'},
        'degraded': {'segment': 'bg-degraded'},
        'paused': {'segment': 'bg-paused'},
        'info': {'segment': 'bg-info'},
        'ai': {'segment': 'bg-ai'},
      },
    },
    defaultVariants: {kUptimeBarSizeAxis: 'md', kUptimeBarStatusAxis: 'up'},
  );
  return recipe(variants: variants, classNames: classNames);
}

/// Resolves only the segment slot className for a single [StatusKey] name.
///
/// Convenience wrapper used by [UptimeBar] when building the per-segment row:
/// avoids re-running the full slot recipe for every segment and lets callers
/// reason about segment styling independently from the outer track.
///
/// ```dart
/// final segmentCls = uptimeBarSegmentClassName('down');
/// ```
String uptimeBarSegmentClassName(String statusName) {
  final classes = uptimeBarRecipe(variants: {kUptimeBarStatusAxis: statusName});
  return classes['segment'] ?? '';
}
