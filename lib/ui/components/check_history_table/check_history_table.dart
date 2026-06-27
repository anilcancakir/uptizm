import 'package:flutter/widgets.dart';
import 'package:magic/magic.dart';

import '../../../app/mocks/monitors.dart';
import '../status_dot/index.dart';
import 'check_history_table.recipe.dart';

/// **The Recent Checks History Table**
///
/// Renders a column of recent probe results: each row shows a timestamp,
/// a [StatusDot] + status label, a response time, and a region — all on a
/// single line that fits narrow screens without horizontal scrolling.
///
/// Timestamps and numeric columns use Geist Mono tabular figures so digits
/// align across rows. Wide cells carry `min-w-0` to prevent overflow.
///
/// Uses [checkHistoryTableRecipe] for all styling; no raw hex, `Color(0xFF...)`,
/// or `Colors.*` anywhere. Composes [StatusDot] instead of duplicating dot logic.
///
/// ### Example Usage:
///
/// ```dart
/// // All recent checks from the fixture:
/// CheckHistoryTable(rows: recentChecks)
///
/// // A custom subset:
/// CheckHistoryTable(
///   rows: recentChecks.take(3).toList(),
/// )
/// ```
@immutable
class CheckHistoryTable extends StatelessWidget {
  /// The ordered list of check results to display.
  ///
  /// Typically sourced from [recentChecks] or a live API response.
  final List<CheckRow> rows;

  /// Creates a [CheckHistoryTable] for the given [rows].
  const CheckHistoryTable({super.key, required this.rows});

  /// Formats an optional response-time value.
  ///
  /// Returns `'—'` when [ms] is null (probe timed out or produced no timing).
  static String _fmtMs(int? ms) => ms == null ? '—' : '${ms}ms';

  @override
  Widget build(BuildContext context) {
    // 1. Resolve all slot classNames once; no per-row overhead.
    final classes = checkHistoryTableRecipe();

    // 2. Build the header row.
    final header = _buildHeader(classes);

    // 3. Build each check row.
    final checkRows = [for (final row in rows) _buildRow(row, classes)];

    // 4. Stack header + rows in a single column container.
    return WDiv(className: classes['table'], children: [header, ...checkRows]);
  }

  // ---------------------------------------------------------------------------
  // Private builders
  // ---------------------------------------------------------------------------

  Widget _buildHeader(Map<String, String> classes) {
    return WDiv(
      className: classes['header'],
      children: [
        // Time column (flex-1 to consume available width).
        WDiv(
          className: 'flex-1',
          child: WText('Time', className: classes['th']),
        ),
        // Status column (flex-2 for the dot + label pair).
        WDiv(
          className: 'flex-2',
          child: WText('Status', className: classes['th']),
        ),
        // Response column (fixed width, right-aligned numeric header).
        WDiv(
          className: 'w-24',
          child: WText('Response', className: classes['thRight']),
        ),
        // Region column (fixed width).
        WDiv(
          className: 'w-24',
          child: WText('Region', className: classes['th']),
        ),
      ],
    );
  }

  Widget _buildRow(CheckRow row, Map<String, String> classes) {
    return WDiv(
      className: classes['row'],
      children: [
        // Timestamp — tabular-nums, muted mono, flex-1.
        WDiv(
          className: 'flex-1',
          child: WText(row.time, className: classes['cellMuted']),
        ),
        // StatusDot + status label — flex-2, min-w-0.
        WDiv(
          className: 'flex-2 ${classes['statusCell'] ?? ''}',
          children: [
            StatusDot(row.status),
            WText(row.status.label, className: 'min-w-0 text-sm text-fg'),
          ],
        ),
        // Response time — right-aligned numeric, fixed width.
        WDiv(
          className: 'w-24',
          child: WText(_fmtMs(row.responseMs), className: classes['cellMono']),
        ),
        // Region — muted mono, fixed width.
        WDiv(
          className: 'w-24',
          child: WText(row.region, className: classes['cellMuted']),
        ),
      ],
    );
  }
}
