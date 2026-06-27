import 'package:magic/magic.dart';

/// Builds the notification-center panel [WindRecipe].
///
/// The recipe encodes only the outer panel shell (a contained surface card
/// with a hairline border and `lg` rounding, sized for the overlay the shell
/// mounts it in). All internal layout tokens (header row, rows, separators,
/// footer) are applied inline in [NotificationCenter] using fixed semantic
/// strings, mirroring the established `ai_insight` recipe shape where only the
/// container shape varies through the recipe.
///
/// ### Slot structure
///
/// ```
/// NotificationCenter
/// └── panel (bg-surface, border, lg rounded, w-80, flex-col, overflow-hidden)
///     ├── header row ("Notifications" + "Mark all read")
///     ├── separator (border-t)
///     ├── notification rows (StatusDot + title/detail/time + unread marker)
///     ├── separator (border-t)
///     └── footer row ("Notification settings")
/// ```
///
/// Emission order: `base`.
///
/// Token reference:
/// - Panel: `w-80 max-w-full bg-surface border border-color-border rounded-lg`
/// - Header label: `text-sm font-semibold text-fg`
/// - Row title (unread): `text-sm font-medium text-fg`
/// - Row title (read): `text-sm text-fg-muted`
/// - Row detail: `text-xs text-fg-muted truncate`
/// - Row time: `font-mono text-xs text-fg-muted`
const WindRecipe notificationCenterRecipe = WindRecipe(
  base:
      'flex flex-col w-80 max-w-full overflow-hidden rounded-lg '
      'bg-surface border border-color-border',
);
