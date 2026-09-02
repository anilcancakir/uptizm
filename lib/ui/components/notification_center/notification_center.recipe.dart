import 'package:magic/magic.dart';

/// The kind axis key for the notification-centre recipe
/// (`AppNotificationKind.<value>.name`).
const String kNotificationCenterKindAxis = 'kind';

/// Builds the notification-row indicator [WindRecipe].
///
/// This recipe IS uptizm's half of the package's notification row: what a
/// notification kind looks like is this product's vocabulary, and
/// `magic_notifications` deliberately carries none of it. The package wraps
/// whatever the `notifications.icon` slot returns in a 32px neutral circle, so
/// the tile here is `size-8` and covers it exactly; the solid [StatusDot] then
/// sits in the middle of the kind's own soft tone.
///
/// Emission order: `base ++ kind-variant ++ caller`.
///
/// Kind -> soft token mapping (the solid dot tone comes from `statusDotRecipe`
/// through `AppNotificationKind.status`, so the two cannot drift):
/// - down / incident: `bg-down-soft`
/// - up / resolved:   `bg-up-soft`
/// - degraded:        `bg-degraded-soft`
/// - ai:              `bg-ai-soft`
const WindRecipe notificationCenterRecipe = WindRecipe(
  base: 'size-8 shrink-0 rounded-full flex items-center justify-center',
  variants: {
    kNotificationCenterKindAxis: {
      'down': 'bg-down-soft',
      'up': 'bg-up-soft',
      'degraded': 'bg-degraded-soft',
      'incident': 'bg-down-soft',
      'resolved': 'bg-up-soft',
      'ai': 'bg-ai-soft',
    },
  },
  defaultVariants: {kNotificationCenterKindAxis: 'incident'},
);

// The bell's four styling surfaces, in uptizm's vocabulary.
//
// `NotificationDropdown` ships Wind's own palette as its defaults (`bg-white`,
// `text-gray-500`, `bg-red-500`) and its docblock says plainly why an adopter
// must answer: those read as a foreign control beside an app whose other
// controls are written in semantic aliases. Both shells mount that widget, so
// the answer lives here once rather than twice.
//
// Each override REPLACES its default outright, layout tokens included, because
// Wind's last-wins is per family and `bg-*` and `dark:bg-*` are two families:
// an appended light-only override would leave `dark:bg-gray-800` alive. So each
// constant below carries its geometry as well as its palette.
//
// Every token here is a theme alias, which is what keeps `bin/check`'s
// design-tokens job meaningful for this control: the raw hexes live in
// `wind_theme.g.dart` and `uptizm_status_tokens.dart`, each already paired with
// its `dark:` peer.

/// The bell's trigger surface (the box around the glyph).
///
/// Transparent at rest with the hover tone on the BACKGROUND only, matching the
/// sibling shell controls. The glyph's own colour is
/// [kNotificationBellTriggerIconClassName] rather than inherited from here, the
/// way the package's own example writes it; the cost is that hovering no longer
/// brightens the glyph itself the way the pre-package bell did, and the gain is
/// a colour that does not depend on Wind propagating text tone into a `WIcon`.
///
/// `active:` is not decoration and must not be dropped. The widget computes the
/// open state itself and passes it down as a Wind state
/// (`WDiv(states: {if (isOpen) 'active', if (isHovering) 'hover'})`), so this
/// string is the only place that state can be given a tone. Without it the bell
/// shows nothing at all while its own panel is open, which on a touch device,
/// where there is no hover to fall back on, is the whole affordance. The
/// package's own docblock says the same: "an override that omits them ships a
/// trigger with no press or hover feedback". The tone matches `hover:` because
/// the package default does too, and one prefix is enough per token: the alias
/// already carries its `dark:` peer, so `dark:active:` would be double-prefixing
/// it. The same pair is already shipped on the sidebar's nav items.
const String kNotificationBellTriggerClassName = '''
  w-9 h-9 shrink-0 rounded-md flex items-center justify-center
  hover:bg-surface-container active:bg-surface-container
''';

/// The bell glyph: shell control size, muted foreground.
const String kNotificationBellTriggerIconClassName =
    'text-[18px] text-fg-muted';

/// The feed panel.
///
/// `surface-container` rather than the `surface` the package example uses:
/// DESIGN.md's background hierarchy puts cards and panels one level above the
/// page canvas, and this panel floats over that canvas. `rounded-lg` for the
/// same reason (DESIGN.md gives cards and dialogs `lg`, not `xl`).
const String kNotificationBellPanelClassName = '''
  w-80 max-w-full rounded-lg shadow-xl
  bg-surface-container border border-color-border
''';

/// The unread-count pill.
///
/// `bg-down` deliberately, not a red of its own: DESIGN.md ties `down` and
/// `destructive` to one hex so an outage and a danger surface read identically,
/// and an unread bell during an incident is the former.
const String kNotificationBellBadgeClassName = '''
  min-w-[16px] h-4 px-1 rounded-full bg-down
  flex items-center justify-center
''';

/// The unread count inside the pill.
///
/// `text-on-destructive` because the pill is `bg-down`, which has an `on-` peer;
/// a plain white here was wrong in dark mode, where `down` lightens to #FF645F.
const String kNotificationBellBadgeTextClassName =
    'text-[10px] font-semibold text-on-destructive';
