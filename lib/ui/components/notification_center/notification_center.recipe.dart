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
