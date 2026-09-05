import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import 'switch_row.dart';

/// Static variant-matrix preview for [SwitchRow].
///
/// Every row is boxed to a phone-width column, because the shape worth looking
/// at is the label wrapping rather than overflowing. The long captions are the
/// real Turkish strings from the escalation editor, which is the pair that
/// actually overflowed at 390pt before this component existed: an English-only
/// preview would show nothing wrong.
class SwitchRowPreview extends StatelessWidget {
  /// Creates the SwitchRow preview.
  const SwitchRowPreview({super.key});

  /// One captioned row, constrained to [width] so the wrap is visible.
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
          'Short label, on',
          320,
          SwitchRow(value: true, label: 'Notify subscribers', onChanged: _noop),
        ),
        _row(
          'Short label, off',
          320,
          SwitchRow(value: false, label: 'Follow redirects', onChanged: _noop),
        ),
        _row(
          'Long label wraps rather than overflowing',
          320,
          SwitchRow(
            value: true,
            label: 'Onaylanana kadar son basamağı tekrarla',
            onChanged: _noop,
          ),
        ),
        _row(
          'Longest label, two lines beside a one-line switch',
          320,
          SwitchRow(
            value: false,
            label: 'Yeni izleyiciler için varsayılan politika olarak kullan',
            onChanged: _noop,
          ),
        ),
        _row(
          'Narrower still, 260pt',
          260,
          SwitchRow(
            value: true,
            label: 'Yeni izleyiciler için varsayılan politika olarak kullan',
            onChanged: _noop,
          ),
        ),
      ],
    );
  }
}

/// A no-op handler, so the preview renders an interactive-looking control
/// without owning state.
void _noop(bool value) {}
