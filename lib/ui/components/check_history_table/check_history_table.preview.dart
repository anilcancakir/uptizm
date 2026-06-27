import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import 'check_history_table.dart';

/// Static fixture preview for [CheckHistoryTable].
///
/// Renders the full [recentChecks] fixture so the catalog shows all
/// representative status states (up/degraded/down) in one pass. One preview
/// class per file is the canonical atomic-component contract.
class CheckHistoryTablePreview extends StatelessWidget {
  /// Creates the check history table preview.
  const CheckHistoryTablePreview({super.key});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-6 p-6',
      children: [
        // Full fixture — all 6 recent check rows.
        _Section(
          label: 'all recent checks · 6 rows',
          child: CheckHistoryTable(rows: recentChecks),
        ),

        // Short slice — 3 rows for a more compact preview.
        _Section(
          label: 'recent checks · 3 rows',
          child: CheckHistoryTable(rows: recentChecks.take(3).toList()),
        ),
      ],
    );
  }
}

/// Internal preview section wrapper — label + child column.
class _Section extends StatelessWidget {
  final String label;
  final Widget child;

  const _Section({required this.label, required this.child});

  @override
  Widget build(BuildContext context) {
    return WDiv(
      className: 'flex flex-col gap-2',
      children: [
        WText(label, className: 'font-mono text-xs text-fg-muted'),
        child,
      ],
    );
  }
}
