import 'package:flutter/material.dart' show Icons;
import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';
// Hide magic_starter's EmptyState so the local uptizm EmptyState wins; only the
// Button is needed from the starter here.
import 'package:magic_starter/magic_starter.dart' hide EmptyState;

import 'empty_state.dart';

/// Static preview for [EmptyState].
///
/// Mirrors the design lab `EmptyState.preview.tsx`: an empty monitors list with
/// a bare monitor glyph, copy, and a primary action, wrapped in a bordered
/// surface card.
class EmptyStatePreview extends StatelessWidget {
  /// Creates the empty-state preview.
  const EmptyStatePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'p-6',
      child: WDiv(
        className: 'rounded-lg border border-color-border bg-surface',
        child: EmptyState(
          icon: Icons.monitor_outlined,
          title: 'No monitors yet',
          description:
              'Create your first monitor to start tracking uptime, latency, '
              'and incidents across regions.',
          action: Button(
            intent: ButtonIntent.primary,
            onPressed: () {},
            child: const WText('Create your first monitor'),
          ),
        ),
      ),
    );
  }
}
