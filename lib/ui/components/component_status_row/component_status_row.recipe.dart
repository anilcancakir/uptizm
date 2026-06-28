import 'package:magic/magic.dart';

/// Builds the [WindSlotRecipe] for the ComponentStatusRow component.
///
/// Ported from the design lab `component-status-row.variants.ts`: a public
/// status-page row (lighter than MonitorListRow) with the component name on the
/// left, an optional 90-day uptime bar, and a status badge on the right.
///
/// Token divergences from the design lab (uptizm neutral aliases):
/// `border-border` -> `border-color-border`, `text-muted-foreground` ->
/// `text-fg-muted`. The source `last:border-b-0` has no Wind equivalent
/// (structural pseudo-variants are unsupported); each row carries its own
/// bottom border.
///
/// ### Slot structure
/// ```
/// root       — flex-col gap, bottom hairline + vertical padding between rows
/// head       — row: name (left) + StatusBadge (right), space-between
/// name       — text-sm font-medium fg
/// bar        — full-width wrapper for the UptimeBar
/// footer     — row: "90 days ago" / uptime% / "Today", space-between
/// footerText — font-mono text-xs tabular-nums muted (applied to each span)
/// ```
const WindSlotRecipe componentStatusRowRecipe = WindSlotRecipe(
  slots: {
    'root': 'flex flex-col gap-2 border-b border-color-border py-4',
    'head': 'flex flex-row items-center justify-between gap-4',
    'name': 'text-sm font-medium text-fg',
    'bar': 'w-full',
    'footer': 'flex flex-row items-center justify-between',
    'footerText': 'font-mono text-xs tabular-nums text-fg-muted',
  },
);
