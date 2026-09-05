import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' show MSSwitch;

import 'switch_row.recipe.dart';

/// A labelled toggle: an [MSSwitch] with its text label beside it.
///
/// One definition, because six screens had grown their own private
/// `_buildSwitchRow` and they had already drifted. Five carried
/// `min-w-0` on the label, which does nothing without a flex share to shrink
/// into, and the sixth had been repaired in place with `flex-1 min-w-0` and a
/// comment recording the measurement. So the codebase held the fix and five
/// copies of the bug at the same time, which is exactly what a shared component
/// prevents. See [switchRowRecipe] for the layout reasoning and the measurement.
///
/// The Dart [Switch] is toggle-only and renders no label of its own, which is
/// why the label is a sibling here rather than a property of the control.
/// [label] is also the switch's accessibility name, so a screen reader announces
/// the same words the operator reads.
///
/// ### Example
/// ```dart
/// SwitchRow(
///   label: trans('uptizm.teams.escalation_editor_repeat_label'),
///   value: _repeatLastStep,
///   onChanged: (bool value) => setState(() => _repeatLastStep = value),
/// )
/// ```
@immutable
class SwitchRow extends StatelessWidget {
  /// The text beside the toggle, and the toggle's accessibility name.
  final String label;

  /// Whether the toggle reads on.
  final bool value;

  /// Invoked with the new value when the operator flips the toggle.
  final ValueChanged<bool> onChanged;

  /// Appended to the row's own className, for per-caller spacing only.
  ///
  /// Emission order is recipe then caller, so this can override the row. It
  /// cannot reach the LABEL, which is deliberate: the label's `flex-1 min-w-0`
  /// is the fix, and a caller that could replace it would reintroduce the
  /// overflow one screen at a time.
  final String? className;

  /// Creates a [SwitchRow].
  const SwitchRow({
    super.key,
    required this.label,
    required this.value,
    required this.onChanged,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: switchRowRecipe()(className: className),
      children: <Widget>[
        MSSwitch(value: value, onChanged: onChanged, semanticLabel: label),
        WText(label, className: switchRowLabelClassName),
      ],
    );
  }
}
