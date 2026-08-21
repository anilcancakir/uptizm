import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
import 'package:magic_starter/magic_starter.dart' show ButtonIntent, MSButton;

import 'form_actions.recipe.dart';

/// The action row that closes a form: an optional Cancel and the primary submit,
/// right-aligned at the bottom of the fields.
///
/// One definition, because a form's submit is the one control a user looks for
/// in the same place on every screen. Two editors used to put theirs in the page
/// header instead, where it competed with the title for width: on a phone the
/// status-page editor rendered `Sweep S...` beside two buttons. The monitor form
/// already ended in a footer like this one; this is that shape, extracted so the
/// three cannot drift.
///
/// The submit is auto-width and never full-width. A Wind full-width button hands
/// its row an unbounded child and aborts the layout, so the row flows (`wrap`)
/// rather than stretching its children.
///
/// ### Example
/// ```dart
/// FormActions(
///   submitLabel: trans('uptizm.status.editor_form_save'),
///   isSubmitting: isSubmitting,
///   onSubmit: () => submitOnce(_save),
/// )
/// ```
@immutable
class FormActions extends StatelessWidget {
  /// Label of the primary submit button.
  final String submitLabel;

  /// Invoked on submit. Null renders the button disabled.
  final VoidCallback? onSubmit;

  /// Whether a submit is in flight.
  ///
  /// Passed to [MSButton.isLoading], which is the GUARD and not only the
  /// spinner: the button drops its tap while loading, so a double tap cannot
  /// create two of anything.
  final bool isSubmitting;

  /// Label of the optional Cancel button. Null renders no Cancel.
  final String? cancelLabel;

  /// Invoked on cancel. Ignored when [cancelLabel] is null.
  final VoidCallback? onCancel;

  /// Optional className appended after the recipe output.
  final String? className;

  /// Creates a [FormActions] row.
  const FormActions({
    super.key,
    required this.submitLabel,
    required this.onSubmit,
    this.isSubmitting = false,
    this.cancelLabel,
    this.onCancel,
    this.className,
  });

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: formActionsRecipe()(className: className),
      children: <Widget>[
        if (cancelLabel != null)
          MSButton(
            intent: ButtonIntent.secondary,
            onPressed: isSubmitting ? null : onCancel,
            child: WText(cancelLabel!),
          ),
        MSButton(
          // Named explicitly, because the label does not survive the loading
          // state: wind's `WButton` REPLACES its child with the spinner
          // (`isLoading ? _buildLoadingContent(styles) : child`), so there is no
          // text left for `MergeSemantics` to absorb and the submit control
          // reaches a screen reader as an unnamed button for exactly as long as
          // the request is in flight. Naming it costs the node's reported
          // focusability, since wind's label branch sets `excludeSemantics`;
          // Flutter's own traversal still reaches the button, and a control that
          // cannot be identified is the worse of the two.
          semanticLabel: submitLabel,
          isLoading: isSubmitting,
          onPressed: onSubmit,
          child: WText(submitLabel),
        ),
      ],
    );
  }
}
