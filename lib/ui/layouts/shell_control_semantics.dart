import 'package:flutter/widgets.dart';

/// Wraps one of the shell's popover controls so assistive technology announces
/// it ONCE, and by name.
///
/// `WPopover` wraps its trigger in `Semantics(button: true, onTap: toggle)`
/// because its own toggle runs on a raw `Listener`, which is invisible to
/// assistive technology. That node carries no label, and the trigger content
/// underneath produces a node of its own, so every one of the shell's three
/// popover controls rendered TWO overlapping buttons at identical bounds.
/// Measured in the DOM at 1280px: the team switcher, the bell and the account
/// menu each appeared twice, and none of the six carried an `aria-label`. A
/// screen reader read each control twice, and the bell's only accessible name
/// was the unread COUNT ("14"), which says nothing about what it opens.
///
/// [MergeSemantics] has to sit ABOVE the popover rather than inside its
/// `triggerBuilder`: the duplicate node is the popover's own ancestor
/// `Semantics`, so merging the trigger's subtree alone still leaves two. The
/// popover PANEL is not swallowed by the merge, because `OverlayPortal` renders
/// it into the Overlay rather than under this subtree.
///
/// Measured after the change: one node, named "Notifications 14".
///
/// ### Example
/// ```dart
/// ShellControlSemantics(
///   label: trans('uptizm.a11y.notifications'),
///   child: WPopover(triggerBuilder: ..., contentBuilder: ...),
/// )
/// ```
@immutable
class ShellControlSemantics extends StatelessWidget {
  /// The control's accessible name, prepended to whatever its trigger renders.
  final String label;

  /// The popover (or any control) to announce as one named button.
  final Widget child;

  /// Wraps [child] as a single, named button for assistive technology.
  const ShellControlSemantics({
    super.key,
    required this.label,
    required this.child,
  });

  @override
  Widget build(BuildContext context) {
    return MergeSemantics(
      child: Semantics(label: label, button: true, child: child),
    );
  }
}
