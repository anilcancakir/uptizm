import 'package:flutter/widgets.dart';
import 'package:flutter/material.dart' show Icons;
import 'package:magic/magic.dart';

import 'header_action.dart';
import 'package:magic_starter/magic_starter.dart' show ButtonIntent;

/// Static variant-matrix preview for [HeaderAction].
///
/// The component's whole point is that it renders differently on either side of
/// `lg`, and a preview cell inherits the catalog's width. Each row below is
/// therefore wrapped in its own [MediaQuery] so both forms are visible at once
/// instead of only whichever width the catalog happens to be opened at.
class HeaderActionPreview extends StatelessWidget {
  /// Creates the HeaderAction preview.
  const HeaderActionPreview({super.key});

  /// Renders [child] as if the screen were [width] logical pixels wide.
  Widget _atWidth(BuildContext context, double width, Widget child) {
    return MediaQuery(
      data: MediaQuery.of(context).copyWith(size: Size(width, 800)),
      child: child,
    );
  }

  /// One labelled row of the matrix.
  Widget _row(BuildContext context, String caption, double width) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(caption, className: 'text-xs text-fg-muted'),
        _atWidth(
          context,
          width,
          WDiv(
            className: 'flex flex-row items-center gap-3',
            children: [
              HeaderAction(
                icon: Icons.add,
                label: 'New monitor',
                onPressed: () {},
              ),
              HeaderAction(
                icon: Icons.download,
                label: 'Export CSV',
                intent: ButtonIntent.secondary,
                onPressed: () {},
              ),
              HeaderAction(
                icon: Icons.add,
                label: 'New monitor',
                onPressed: null,
              ),
            ],
          ),
        ),
      ],
    );
  }

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        _row(context, 'Phone (402pt): primary, secondary, disabled', 402),
        _row(context, 'Desktop (1280pt): primary, secondary, disabled', 1280),
      ],
    );
  }
}
