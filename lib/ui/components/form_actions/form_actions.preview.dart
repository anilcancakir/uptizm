import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'form_actions.dart';

/// Static variant-matrix preview for [FormActions].
///
/// Each row is boxed to a phone-width column, because the shape worth looking at
/// is the wrap: a Cancel plus a long submit label does not fit one line there and
/// has to flow instead of overflowing.
class FormActionsPreview extends StatelessWidget {
  /// Creates the FormActions preview.
  const FormActionsPreview({super.key});

  /// One captioned row, constrained to [width].
  Widget _row(String caption, double width, Widget child) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: <Widget>[
        WText(caption, className: 'text-xs text-fg-muted'),
        SizedBox(width: width, child: child),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: <Widget>[
        _row(
          'Submit only',
          320,
          const FormActions(submitLabel: 'Save', onSubmit: _noop),
        ),
        _row(
          'With cancel',
          320,
          const FormActions(
            submitLabel: 'Create monitor',
            cancelLabel: 'Cancel',
            onSubmit: _noop,
            onCancel: _noop,
          ),
        ),
        _row(
          'Submitting',
          320,
          const FormActions(
            submitLabel: 'Create status page',
            cancelLabel: 'Cancel',
            isSubmitting: true,
            onSubmit: _noop,
            onCancel: _noop,
          ),
        ),
        _row(
          'Disabled submit',
          320,
          const FormActions(submitLabel: 'Save', onSubmit: null),
        ),
      ],
    );
  }
}

/// A callback the preview can pass in a const constructor.
void _noop() {}
