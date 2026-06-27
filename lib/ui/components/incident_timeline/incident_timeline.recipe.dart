import 'package:magic/magic.dart';

/// The actor axis key for [incidentTimelineRecipe].
const String kTimelineActorAxis = 'actor';

/// The visibility axis key for [incidentTimelineRecipe].
const String kTimelineVisibilityAxis = 'visibility';

/// Builds the incident-timeline [WindSlotRecipe].
///
/// Ported from `incident-timeline.variants.ts`: a vertical rail of events where
/// each node is color-coded by its actor (AI / human / system) so operators can
/// tell at a glance who moved the incident, and each entry carries a
/// public/internal tag plus a relative time.
///
/// ### Slot structure
///
/// ```
/// item    — relative flex row [node, body], bottom padding between entries
/// node    — size-8 rounded-full circle, actor-tinted background
/// icon    — actor glyph, actor-tinted foreground
/// body    — min-w-0 flex-1 column: head + message + optional author
/// head    — wrap row: status + (Auto mode) + tag + time (ml-auto)
/// status  — text-sm font-medium fg
/// tag     — uppercase pill, info tone (public) / muted (internal)
/// time    — ml-auto font-mono muted
/// message — text-sm muted
/// author  — text-xs muted
/// ```
///
/// Token divergences from the design lab: `bg-muted` -> `bg-surface-container`,
/// `text-muted-foreground` -> `text-fg-muted` (uptizm's neutral aliases).
///
/// Emission order per slot: `base ++ actor-variant ++ visibility-variant`.
const WindSlotRecipe incidentTimelineRecipe = WindSlotRecipe(
  slots: {
    'item': 'flex flex-row gap-3',
    // No `flex` here: it would make the node hug its icon (mainAxisSize.min) and
    // drop the size-8 box. A plain sized WDiv keeps the 32px circle; the icon is
    // centered with a Flutter Center in the component.
    'node': 'size-8 shrink-0 rounded-full',
    'icon': 'text-base',
    'body': 'flex flex-col gap-0.5 min-w-0 flex-1 pt-1',
    'head': 'wrap items-center gap-2',
    'status': 'text-sm font-medium text-fg',
    'tag':
        'rounded-sm px-1.5 py-0.5 text-[10px] font-medium uppercase tracking-wide',
    'time': 'ml-auto font-mono text-xs tabular-nums text-fg-muted',
    'message': 'text-sm leading-relaxed text-fg-muted',
    'author': 'text-xs text-fg-muted',
  },
  variants: {
    kTimelineActorAxis: {
      'ai': {'node': 'bg-ai-soft', 'icon': 'text-ai'},
      'human': {'node': 'bg-surface-container', 'icon': 'text-fg'},
      'system': {'node': 'bg-surface-container', 'icon': 'text-fg-muted'},
    },
    kTimelineVisibilityAxis: {
      'public': {'tag': 'bg-info-soft text-info-soft-foreground'},
      'internal': {'tag': 'bg-surface-container text-fg-muted'},
    },
  },
  defaultVariants: {
    kTimelineActorAxis: 'system',
    kTimelineVisibilityAxis: 'internal',
  },
);
