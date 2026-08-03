import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart';

import '../../../app/enums/status_key.dart';
import '../status_badge/index.dart';
import 'maintenance_card.recipe.dart';

/// The phase of a maintenance window, derived from the clock rather than stored.
///
/// The backend keeps no lifecycle column on a window: whether it is upcoming,
/// running or over is `starts_at`/`ends_at` against the current time, decided by
/// whoever renders it. This enum names the three answers so the card and its
/// caller cannot disagree about them.
enum MaintenancePhase {
  /// Starts in the future.
  upcoming,

  /// The clock is inside the window.
  active,

  /// Already ended.
  finished;

  /// Resolves the phase of a window bounded by [startsAt] and [endsAt].
  ///
  /// Unknown bounds read as [upcoming]: a window whose dates did not survive the
  /// wire is not evidence that its work is done.
  static MaintenancePhase resolve(DateTime? startsAt, DateTime? endsAt, {
    DateTime? now,
  }) {
    final DateTime at = now ?? DateTime.now();

    if (endsAt != null && endsAt.isBefore(at)) return finished;
    if (startsAt != null && startsAt.isAfter(at)) return upcoming;
    if (startsAt == null) return upcoming;

    return active;
  }
}

/// **Maintenance window summary card.**
///
/// The sibling of [IncidentCard], and deliberately built from the same parts: an
/// [MSCard] with `noPadding`, a left accent stripe, and a mono meta row of
/// `·`-separated facts. A maintenance row sitting next to incident rows in the
/// same list has to read as the same kind of object.
///
/// It differs in one place on purpose: the title shares the badge row rather than
/// taking a line of its own, because this row carries a trailing action and a
/// window has fewer facts than an incident. Two rows, not three.
///
/// The stripe encodes [phase] (see the recipe for why not impact), the badge
/// names it in words, and the meta row carries the affected components and the
/// window bounds. [onCancel] renders a trailing action in the badge row; passing
/// null drops it, which is what a read-only surface wants.
///
/// The bounds arrive PRE-FORMATTED as [range]: formatting is the caller's
/// business because it owns the locale and the timezone decision (the wire is
/// UTC, the operator reads local), and a component that formats dates itself
/// ends up carrying a second copy of that policy.
///
/// ### Example
/// ```dart
/// MaintenanceCard(
///   title: 'Database upgrade',
///   phase: MaintenancePhase.upcoming,
///   phaseLabel: 'Scheduled',
///   components: const ['Checkout', 'API'],
///   range: '08-03 17:30 → 08-03 18:30',
///   onCancel: () => controller.delete(window.id),
///   cancelLabel: 'Cancel',
/// )
/// ```
@immutable
class MaintenanceCard extends StatelessWidget {
  /// The operator-authored window title.
  final String title;

  /// The window's phase, driving the stripe tone.
  final MaintenancePhase phase;

  /// The phase in words, already localised (e.g. "Scheduled").
  final String phaseLabel;

  /// The affected component names, as the page publishes them.
  final List<String> components;

  /// The window bounds, already formatted and localised by the caller.
  final String range;

  /// Whether the window holds alerts while it runs, shown as a quiet badge so an
  /// operator can tell a silent window from an announcement-only one.
  final bool suppressesAlerts;

  /// The label of the alert-hold badge, already localised.
  final String? suppressLabel;

  /// Cancels the window. Null renders no action.
  final VoidCallback? onCancel;

  /// The cancel action's label, already localised.
  final String? cancelLabel;

  /// Optional extra classNames appended to the card body.
  final String? className;

  /// Creates a [MaintenanceCard].
  const MaintenanceCard({
    super.key,
    required this.title,
    required this.phase,
    required this.phaseLabel,
    required this.range,
    this.components = const [],
    this.suppressesAlerts = false,
    this.suppressLabel,
    this.onCancel,
    this.cancelLabel,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    return MSCard(
      noPadding: true,
      child: WDiv(
        className: 'relative overflow-hidden',
        children: [
          // 1. Left accent stripe: the phase, in colour.
          WDiv(
            className: maintenanceCardRecipe(
              variants: {kMaintenanceCardPhaseAxis: phase.name},
            ),
          ),

          // 2. Body, offset for the stripe exactly as IncidentCard offsets it.
          WDiv(
            className: className == null
                ? 'flex flex-col gap-2 p-4 pl-5'
                : 'flex flex-col gap-2 p-4 pl-5 $className',
            children: [_buildHeader(), _buildMeta()],
          ),
        ],
      ),
    );
  }

  /// The top row: phase badge, title, the alert-hold badge, and Cancel, flowing
  /// left to right and wrapping on a narrow screen.
  ///
  /// `wrap`, exactly like [IncidentCard]'s header, and NOT a flex row with a
  /// right-aligned action. Three shapes were tried before this one and all three
  /// overflowed by ~560 pixels: an empty `flex-1` [WDiv] as a spacer, a Flutter
  /// [Row] with a [Spacer], and `flex-1` on the title itself. The cause is one
  /// level up: the card body sits inside `relative overflow-hidden`, which hands
  /// its children an UNBOUNDED width, and nothing that has to resolve against a
  /// main extent can work there. `wrap` sizes to content and needs no extent,
  /// which is why the incident card uses it.
  ///
  /// So Cancel sits after the title rather than against the right edge. That also
  /// fixes what prompted this rewrite: a card with three unweighted lines in the
  /// top-left and Cancel adrift in the empty right half.
  Widget _buildHeader() {
    return WDiv(
      className: 'wrap items-center gap-2',
      children: [
        StatusBadge(_badgeKey, label: phaseLabel),

        WText(title, className: 'text-sm font-semibold text-fg'),

        if (suppressesAlerts && suppressLabel != null)
          MSBadge(suppressLabel!, tone: BadgeTone.outline),

        if (onCancel != null && cancelLabel != null)
          MSButton(
            size: ButtonSize.sm,
            intent: ButtonIntent.secondary,
            onPressed: onCancel,
            child: WText(cancelLabel!),
          ),
      ],
    );
  }

  /// The meta row: affected components then the bounds, `·`-separated, in the
  /// mono tabular-nums treatment every timestamp in this app uses.
  Widget _buildMeta() {
    return WDiv(
      className:
          'wrap items-center gap-x-2 gap-y-1 font-mono text-xs tabular-nums '
          'text-fg-muted',
      children: [
        if (components.isNotEmpty) ...[
          WText(components.join(', ')),
          WText('·', className: 'text-fg-disabled'),
        ],
        WText(range),
      ],
    );
  }

  /// The badge tone for the phase: the maintenance blue while a window matters,
  /// neutral once it is spent.
  StatusKey get _badgeKey =>
      phase == MaintenancePhase.finished ? StatusKey.paused : StatusKey.info;
}
